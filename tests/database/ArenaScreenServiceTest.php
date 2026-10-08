<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\WidenBattleLogsType;
use App\Services\PVE\ArenaScreenService;
use App\Services\PVE\BattleJournalService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * w2-n7-combat-02 — ядро арены: запись дуэли в журнал, атомарный анти-спам кулдаун, замки выключенных
 * разделов, тумблер «открыт к дуэлям». Бой настоящий (`simulateFight` под `mt_srand`), схема — из миграций.
 *
 * «Одновременно» проверяется вторым соединением MySQL (= второй процесс): пока оно держит блокировку
 * дуэли вызывающего, вызов не проводит бой и ничего не пишет.
 *
 * @internal
 */
final class ArenaScreenServiceTest extends CIUnitTestCase
{
    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-06-04-230000_W17AddCharacterDuelsOpen',
        '2026-06-05-100000_W18CreatePvpLadderTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2025-02-08-194808_CreateOutfitsTable',
        '2025-02-08-195713_CreateWeaponsTable',
        '2025-02-10-224703_CreateCharactersOutfitsTable',
        '2025-02-11-115603_CreateCharactersWeaponsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'pvp_ladder', 'factions', 'character_factions',
        'outfits', 'weapons', 'characters_outfits', 'characters_weapons', 'game_settings', 'battle_logs',
    ];

    private const A = 1;
    private const B = 2;

    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query(
                'CREATE TABLE battle_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, battle_type VARCHAR(3) NOT NULL,'
                . ' player1_id INT NULL, player2_id INT NULL, winner_id INT NULL, created_at DATETIME NOT NULL,'
                . ' finished_at DATETIME NOT NULL, log_data LONGTEXT NOT NULL)'
            );
            $this->conn->resetDataCache();
            require_once APPPATH . 'Database/Migrations/2026-12-17-100000_WidenBattleLogsType.php';
            (new WidenBattleLogsType($forge instanceof Forge ? $forge : null))->up();
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testChallengeWritesOneDuelVisibleToBothAndTouchesNoStats(): void
    {
        $before = $this->stats();

        mt_srand(42);
        $duel = (new ArenaScreenService())->challenge($this->character(self::A), self::B, true);

        $this->assertTrue($duel['ok'], json_encode($duel, JSON_UNESCAPED_UNICODE) ?: '');
        $rows = $this->duelRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($duel['battle_id'], (int) $row['id']);
        $this->assertSame(self::A, (int) $row['player1_id'], 'вызвавший');
        $this->assertSame(self::B, (int) $row['player2_id'], 'соперник');
        $this->assertSame($duel['winner_id'], (int) $row['winner_id']);
        $this->assertContains($duel['winner_id'], [self::A, self::B]);

        $log = json_decode((string) $row['log_data'], true);
        $this->assertIsArray($log);
        $this->assertTrue($log['duel'] ?? null, 'пометка «дуэль, без потерь»');
        $this->assertNotEmpty($log['rounds'] ?? null, 'раунды');
        $this->assertSame($duel['reason'], $log['outcome']['reason'] ?? null);
        // Локация — приватные данные: дуэль (спорт без места) не хранит координат ни одного бойца.
        foreach (['attacker', 'defender'] as $side) {
            $this->assertIsArray($log['characters'][$side] ?? null, $side);
            $this->assertArrayNotHasKey('coords', $log['characters'][$side], "{$side}: координаты в журнале дуэли");
        }
        $this->assertStringNotContainsString('coordinate', (string) $row['log_data']);
        $this->assertStringNotContainsString('"cell"', (string) $row['log_data']);

        $this->assertSame($before, $this->stats(), 'здоровье, опыт, золото и уровень обоих не меняются');

        $journal = new BattleJournalService();
        foreach ([self::A, self::B] as $who) {
            $card = $journal->card($who, (int) $row['id']);
            $this->assertTrue($card['ok'], "бой виден персонажу {$who}");
            $this->assertTrue($card['battle']['duel']);
            $this->assertGreaterThan(0, $card['battle']['rounds_total']);
            $this->assertSame($duel['winner_id'] === $who ? 'win' : 'loss', $card['battle']['result']);
            $this->assertSame([(int) $row['id']], array_column($journal->listFor($who, 10), 'id'));
        }
    }

    public function testRepeatRightAwayIsRefusedWithCooldownAndNoSecondDuel(): void
    {
        $core = new ArenaScreenService();
        $this->assertTrue($core->challenge($this->character(self::A), self::B, true)['ok']);
        $ladder = $this->ladderRows();

        $again = $core->challenge($this->character(self::A), self::B, true);

        $this->assertFalse($again['ok']);
        $this->assertSame(ArenaScreenService::CODE_COOLDOWN, $again['code']);
        $this->assertMatchesRegularExpression('/^Подожди \d+ сек\. перед следующей дуэлью\.$/u', $again['message']);
        $this->assertCount(1, $this->duelRows(), 'одна строка DUEL');
        $this->assertSame($ladder, $this->ladderRows(), 'одно начисление рейтинга');
    }

    public function testConcurrentChallengeFromAnotherConnectionDoesNotDuel(): void
    {
        // Второе соединение = второй процесс (двойной тап, веб + бот), его дуэль ещё идёт.
        $other = Database::connect(null, false);
        $lock  = 'ww-duel-' . $this->conn->getDatabase() . '-' . self::A;
        $held  = $other->query('SELECT GET_LOCK(?, 0) AS l', [$lock])->getRowArray();
        $this->assertSame(1, (int) ($held['l'] ?? 0));

        try {
            $busy = (new ArenaScreenService(null, null, null, null, 0))->challenge($this->character(self::A), self::B, true);
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$lock]);
            $other->close();
        }

        $this->assertFalse($busy['ok']);
        $this->assertSame(ArenaScreenService::CODE_BUSY, $busy['code']);
        $this->assertSame([], $this->duelRows(), 'ни строки DUEL');
        $this->assertSame([[self::B, 0, 0]], array_map(
            static fn (array $r): array => [(int) $r['character_id'], (int) $r['duel_wins'], (int) $r['duel_losses']],
            $this->ladderRows()
        ), 'рейтинг не тронут');

        // Блокировку отпустили — следующий вызов проходит, и блокировка снова свободна.
        $this->assertTrue((new ArenaScreenService())->challenge($this->character(self::A), self::B, true)['ok']);
        $free = $this->conn->query('SELECT IS_FREE_LOCK(?) AS f', [$lock])->getRowArray();
        $this->assertSame(1, (int) ($free['f'] ?? 0));
    }

    public function testDisabledDuelsLockArenaAndRefuseWithoutWrite(): void
    {
        $this->setBool('pvp.duel.enabled', 0);
        $core = new ArenaScreenService();

        $arena = $core->arena($this->character(self::A));
        $this->assertFalse($arena['enabled']);
        $this->assertSame(ArenaScreenService::LOCK_ARENA, $arena['lock']);
        $this->assertSame([], $arena['roster']);

        $duel = $core->challenge($this->character(self::A), self::B, true);
        $this->assertFalse($duel['ok']);
        $this->assertSame(ArenaScreenService::CODE_DISABLED, $duel['code']);
        $this->assertSame([], $this->duelRows());
    }

    public function testDisabledLadderIsLocked(): void
    {
        $core = new ArenaScreenService();
        $open = $core->ladder(self::A, null);
        $this->assertTrue($open['enabled']);
        $this->assertSame('', $open['lock']);
        $this->assertSame(['Боб'], array_column($open['rows'], 'name'));

        $this->setBool('pvp.ladder.enabled', 0);
        $locked = $core->ladder(self::A, null);
        $this->assertFalse($locked['enabled']);
        $this->assertSame(ArenaScreenService::LOCK_LADDER, $locked['lock']);
        $this->assertSame([], $locked['rows']);
    }

    public function testArenaModelListsOpenFightersAndMyState(): void
    {
        $arena = (new ArenaScreenService())->arena($this->character(self::A));
        $this->assertTrue($arena['enabled']);
        $this->assertSame('', $arena['lock']);
        $this->assertFalse($arena['self_open']);
        $this->assertSame([['id' => self::B, 'name' => 'Боб', 'level' => 12, 'pts' => 5]], $arena['roster']);
    }

    public function testSetDuelsOpenWritesTheFlag(): void
    {
        $core = new ArenaScreenService();
        $this->assertTrue($core->setDuelsOpen(self::A, true));
        $this->assertSame(1, $this->flag(self::A));
        $this->assertFalse($core->setDuelsOpen(self::A, true), 'уже открыт — без изменений');
        $this->assertTrue($core->setDuelsOpen(self::A, false));
        $this->assertSame(0, $this->flag(self::A));
        $this->assertFalse($core->setDuelsOpen(0, true));
    }

    public function testResultTextEscapesNamesForHtml(): void
    {
        $text = ArenaScreenService::resultText(3, 'knockout', 'A<b>', 'A<b>', 'Q&A');
        $this->assertStringContainsString('A&lt;b&gt; ⚔️ Q&amp;A', $text);
        $this->assertStringContainsString('🏆 Победитель: <b>A&lt;b&gt;</b> (нокаут)', $text);
        $this->assertSame('по очкам — осталось больше здоровья', ArenaScreenService::reasonLabel('hp'));
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000041, ?), (8, 771000042, ?)', ['Сан', 'Боб']);
        foreach ([[self::A, 7, 'Сан', 10, 0], [self::B, 8, 'Боб', 12, 1]] as [$id, $tg, $name, $level, $open]) {
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, duels_open)'
                . ' VALUES (?, ?, ?, ?, 1.5, 90, 10, 0.5, 0.01, 0.01, 100, 5, ?)',
                [$id, $tg, $name, $level, $open]
            );
        }
        $this->conn->query('INSERT INTO pvp_ladder (character_id, duel_wins, points) VALUES (?, 0, 5)', [self::B]);

        $now  = date('Y-m-d H:i:s');
        $base = [
            'category' => 'combat', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (['pvp.duel.enabled' => 1, 'pvp.ladder.enabled' => 1] as $key => $v) {
            $this->conn->table('game_settings')->insert($base + [
                'setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $v, 'default_value_text' => (string) $v,
            ]);
        }
        $this->conn->table('game_settings')->insert($base + [
            'setting_key' => 'pvp.attack_cooldown_sec', 'value_type' => 'int', 'value_int' => 30, 'default_value_text' => '30',
        ]);
    }

    private function setBool(string $key, int $value): void
    {
        $this->conn->query('UPDATE game_settings SET value_bool = ? WHERE setting_key = ?', [$value, $key]);
        service('cache')->clean();
    }

    /** @return array<string, mixed> */
    private function character(int $id): array
    {
        $row = $this->conn->query('SELECT * FROM characters WHERE id = ?', [$id])->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function duelRows(): array
    {
        return $this->conn->query("SELECT * FROM battle_logs WHERE battle_type = 'DUEL' ORDER BY id")->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function ladderRows(): array
    {
        return $this->conn->query('SELECT character_id, duel_wins, duel_losses, points FROM pvp_ladder ORDER BY character_id')->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function stats(): array
    {
        return $this->conn->query('SELECT id, health, experience, gold, level, tired FROM characters ORDER BY id')->getResultArray();
    }

    private function flag(int $id): int
    {
        return (int) ($this->conn->query('SELECT duels_open FROM characters WHERE id = ?', [$id])->getRowArray()['duels_open'] ?? -1);
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
