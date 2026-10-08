<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\PVP\BattleJournalAction;
use App\Database\Migrations\WidenBattleLogsType;
use App\Services\PVE\BattleJournalService;
use App\Services\PVE\PveNotificationSender;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * w2-n7-combat-01 — ядро журнала боёв и рендер бота.
 *
 * Схема `battle_logs` — как на проде до спеки (`battle_type VARCHAR(3)`, своей createTable-миграции у
 * таблицы нет), затем настоящая миграция `WidenBattleLogsType` — тем самым проверяется и она: строка
 * `DUEL` до неё под STRICT не вставляется, после — вставляется.
 *
 * Формы `log_data` взяты с прода (PvE: `characters.player|npc`, раунды `final_damage`; PvP v2:
 * `characters.attacker|defender`, раунды `finalDamage`), включая полный дамп персонажа в PvE — модель
 * не должна его выпускать.
 *
 * @internal
 */
final class BattleJournalServiceTest extends CIUnitTestCase
{
    private BaseConnection $conn;

    private const ME    = 501;
    private const OTHER = 502;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-12-17-100000_WidenBattleLogsType.php';
        $this->conn = Database::connect();
        $this->conn->query('DROP TABLE IF EXISTS battle_logs');
        $this->conn->query('
            CREATE TABLE battle_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                battle_type VARCHAR(3) NOT NULL,
                player1_id INT NULL,
                player2_id INT NULL,
                winner_id INT NULL,
                created_at DATETIME NOT NULL,
                finished_at DATETIME NOT NULL,
                log_data LONGTEXT NOT NULL
            )
        ');
        $this->conn->resetDataCache();
        $forge = Database::forge();
        (new WidenBattleLogsType($forge instanceof Forge ? $forge : null))->up();
    }

    protected function tearDown(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS battle_logs');
        parent::tearDown();
    }

    public function testMigrationWidensTypeForDuel(): void
    {
        $id = $this->insert('DUEL', self::ME, self::OTHER, self::ME, $this->pvpLog(self::ME, 'San', self::OTHER, 'Bob', 3));
        $this->assertGreaterThan(0, $id);
        $row = $this->conn->query('SELECT battle_type FROM battle_logs WHERE id = ?', [$id])->getRowArray();
        $this->assertSame('DUEL', $row['battle_type'] ?? null);
    }

    public function testListHoldsOnlyMyFightsNewestFirst(): void
    {
        $pve     = $this->insert('PVE', self::ME, 2, self::ME, $this->pveLog(self::ME, 'San', 13061, 'Главарь Песчаных Волков', 3));
        $foreign = $this->insert('PVE', self::OTHER, 2, self::OTHER, $this->pveLog(self::OTHER, 'Bob', 9, 'Волк', 2));
        // PvE с npc_id = моему id в player2_id: чужой бой не становится моим.
        $npcClash = $this->insert('PVE', self::OTHER, self::ME, self::OTHER, $this->pveLog(self::OTHER, 'Bob', 77, 'Крыса', 1));
        $pvp      = $this->insert('PVP', self::OTHER, self::ME, self::OTHER, $this->pvpLog(self::OTHER, 'Bob', self::ME, 'San', 4));
        $duel     = $this->insert('DUEL', self::ME, self::OTHER, self::ME, $this->pvpLog(self::ME, 'San', self::OTHER, 'Bob', 2));

        $list = (new BattleJournalService())->listFor(self::ME, 10);
        $ids  = array_column($list, 'id');

        $this->assertSame([$duel, $pvp, $pve], $ids);
        $this->assertNotContains($foreign, $ids);
        $this->assertNotContains($npcClash, $ids);

        $byId = array_column($list, null, 'id');
        $this->assertSame('Главарь Песчаных Волков', $byId[$pve]['opponent']);
        $this->assertSame(BattleJournalService::RESULT_WIN, $byId[$pve]['result']);
        $this->assertSame('Bob', $byId[$pvp]['opponent']);
        $this->assertSame('San', $byId[$pvp]['me']);
        $this->assertSame(BattleJournalService::RESULT_LOSS, $byId[$pvp]['result']);
        $this->assertTrue($byId[$duel]['duel']);
        $this->assertSame(BattleJournalService::RESULT_WIN, $byId[$duel]['result']);
        $this->assertArrayNotHasKey('rounds', $byId[$pve]);
    }

    public function testListRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insert('PVE', self::ME, 2, self::ME, $this->pveLog(self::ME, 'San', 100 + $i, 'Волк', 1));
        }
        $this->assertCount(3, (new BattleJournalService())->listFor(self::ME, 3));
    }

    public function testCardOfForeignOrMissingFightIsNotFound(): void
    {
        $foreign = $this->insert('PVE', self::OTHER, 2, self::OTHER, $this->pveLog(self::OTHER, 'Bob', 9, 'Волк', 2));
        $svc     = new BattleJournalService();

        $this->assertSame(['ok' => false, 'code' => BattleJournalService::NOT_FOUND], $svc->card(self::ME, $foreign));
        $this->assertSame(['ok' => false, 'code' => BattleJournalService::NOT_FOUND], $svc->card(self::ME, 999999));
    }

    public function testCardCarriesRoundsWithoutCharacterDump(): void
    {
        $id  = $this->insert('PVE', self::ME, 2, 13061, $this->pveLog(self::ME, 'San', 13061, 'Волк', 3, false));
        $res = (new BattleJournalService())->card(self::ME, $id);

        $this->assertTrue($res['ok']);
        $card = $res['battle'];
        $this->assertSame(BattleJournalService::RESULT_LOSS, $card['result']);
        $this->assertSame(3, $card['rounds_total']);
        $this->assertSame('San', $card['rounds'][0]['attacker']);
        $this->assertTrue($card['rounds'][0]['mine']);
        $this->assertFalse($card['rounds'][1]['mine']);
        $this->assertTrue($card['rounds'][1]['lucky']);
        $this->assertStringNotContainsString('477794709359', (string) json_encode($card), 'дамп персонажа (золото) не выходит из ядра');
    }

    public function testBrokenLogGivesOutcomeWithoutRounds(): void
    {
        $id  = $this->insert('PVP', self::ME, self::OTHER, self::ME, '{not json');
        $res = (new BattleJournalService())->card(self::ME, $id);

        $this->assertTrue($res['ok']);
        $this->assertSame([], $res['battle']['rounds']);
        $this->assertSame(BattleJournalService::RESULT_WIN, $res['battle']['result']);
        $this->assertStringContainsString('не сохранились', BattleJournalAction::renderCard($res['battle']));
    }

    public function testBotCardFitsTelegramLimitOnLongFight(): void
    {
        $long = 'Очень-Длинное-Имя-<b>&Хищника</b>-Из-Глубин-Пустоши';
        $id   = $this->insert('PVE', self::ME, 2, self::ME, $this->pveLog(self::ME, 'San', 13061, $long, 150));
        $res  = (new BattleJournalService())->card(self::ME, $id);
        $this->assertTrue($res['ok']);

        $text = BattleJournalAction::renderCard($res['battle']);
        $this->assertLessThanOrEqual(4096, mb_strlen($text));
        $this->assertStringContainsString('… ещё ', $text);
        $this->assertStringContainsString('&lt;b&gt;&amp;Хищника', $text, 'имя NPC экранировано под HTML');
        $this->assertStringContainsString('150. ', $text, 'последний раунд виден');
        $this->assertStringContainsString("\n1. San", $text, 'первый раунд виден');
    }

    /**
     * duel-baseline-weapon-02: урон и остаток меньше 1 — двумя знаками, а не «−0» (дуэль до фикса била по 0,01).
     * Тот же набор — в `PlayViewsTest::testWebBattleCardShowsDamageBelowOneLikeTheBot` (паритет форматтеров).
     */
    public function testBotCardShowsDamageBelowOneNotAsZero(): void
    {
        $text = BattleJournalAction::renderCard(['id' => 9, 'type' => 'DUEL', 'duel' => true, 'me' => 'Ворон', 'opponent' => 'Сова', 'result' => 'win', 'at' => '2026-10-08 21:05:00', 'rounds_total' => 6, 'rounds' => [
            ['n' => 1, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 0.01, 'hp_after' => 999.99, 'lucky' => false, 'mine' => true],
            ['n' => 2, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 0.04, 'hp_after' => 0.4, 'lucky' => false, 'mine' => false],
            ['n' => 3, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 0.4, 'hp_after' => 12.6, 'lucky' => false, 'mine' => true],
            ['n' => 4, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 2.36, 'hp_after' => 0.01, 'lucky' => false, 'mine' => false],
            ['n' => 5, 'attacker' => 'Ворон', 'defender' => 'Сова', 'damage' => 12.6, 'hp_after' => 2.36, 'lucky' => false, 'mine' => true],
            ['n' => 6, 'attacker' => 'Сова', 'defender' => 'Ворон', 'damage' => 0.0, 'hp_after' => 0.04, 'lucky' => false, 'mine' => false],
        ]]);

        $this->assertStringContainsString('1. Ворон → Сова: −0.01 (осталось 1000 HP)', $text);
        $this->assertStringContainsString('2. Сова → Ворон: −0.04 (осталось 0.4 HP)', $text);
        $this->assertStringContainsString('3. Ворон → Сова: −0.4 (осталось 13 HP)', $text);
        $this->assertStringContainsString('4. Сова → Ворон: −2.4 (осталось 0.01 HP)', $text);
        $this->assertStringContainsString('5. Ворон → Сова: −13 (осталось 2.4 HP)', $text);
        $this->assertStringContainsString('6. Сова → Ворон: промах (осталось 0.04 HP)', $text);
        $this->assertStringNotContainsString('−0 ', $text);
        $this->assertStringNotContainsString('−0 (', $text);
    }

    public function testBotListRendersAndPacksButtons(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insert('PVE', self::ME, 2, self::ME, $this->pveLog(self::ME, 'San', 100 + $i, 'Волк ' . $i, 1));
        }
        $entries = (new BattleJournalService())->listFor(self::ME, BattleJournalService::LIMIT_BOT);
        $text    = BattleJournalAction::renderList($entries);
        $kb      = BattleJournalAction::listKeyboard($entries);

        $this->assertStringContainsString('📜 <b>Мои бои</b>', $text);
        $this->assertStringContainsString('10. ✅ Победа · ⚔️ PvE · Волк 0', $text);
        foreach ($kb['inline_keyboard'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ни одного ряда с одной кнопкой');
        }
        $this->assertSame('battleLog_' . $entries[0]['id'] . '_j', $kb['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringContainsString('Боёв пока нет', BattleJournalAction::renderList([]));
    }

    public function testPveResultKeyboardLeadsToCard(): void
    {
        $this->assertNull(PveNotificationSender::keyboard(null));
        $this->assertNull(PveNotificationSender::keyboard(0));
        $this->assertSame(
            ['inline_keyboard' => [[['text' => '📜 Разбор боя', 'callback_data' => 'battleLog_42']]]],
            PveNotificationSender::keyboard(42)
        );
    }

    private function insert(string $type, int $p1, int $p2, int $winner, string $log): int
    {
        $this->conn->table('battle_logs')->insert([
            'battle_type' => $type,
            'player1_id'  => $p1,
            'player2_id'  => $p2,
            'winner_id'   => $winner,
            'created_at'  => '2026-10-08 14:11:02',
            'finished_at' => '2026-10-08 14:11:02',
            'log_data'    => $log,
        ]);

        return (int) $this->conn->insertID();
    }

    private function pveLog(int $playerId, string $player, int $npcSpawnId, string $npc, int $rounds, bool $playerWins = true): string
    {
        $r = [];
        for ($i = 1; $i <= $rounds; $i++) {
            $mine = $i % 2 === 1;
            $r[]  = [
                'round'                 => $i,
                'attacker'              => $mine ? $player : $npc,
                'defender'              => $mine ? $npc : $player,
                'base_damage'           => 67.5,
                'final_damage'          => 15.25,
                'defender_health_after' => $i === $rounds ? 0 : 100 - $i,
                'luckyStrike'           => ! $mine,
            ];
        }

        return (string) json_encode([
            'characters' => [
                'player' => ['id' => $playerId, 'name' => $player, 'gold' => 477794709359, 'telegram_chat_id' => '6212024580'],
                'npc'    => ['id' => (string) $npcSpawnId, 'npc_id' => '2', 'name' => $npc, 'npc_name_ru' => $npc],
            ],
            'rounds'  => $r,
            'outcome' => ['type' => 'normal', 'winnerId' => $playerWins ? $playerId : $npcSpawnId, 'loserId' => $playerWins ? $npcSpawnId : $playerId],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function pvpLog(int $attId, string $att, int $defId, string $def, int $rounds): string
    {
        $r = [];
        for ($i = 1; $i <= $rounds; $i++) {
            $r[] = [
                'round'               => $i,
                'attacker'            => $i % 2 === 1 ? $att : $def,
                'defender'            => $i % 2 === 1 ? $def : $att,
                'finalDamage'         => $i === 2 ? 0 : 19.58,
                'defenderHealthAfter' => 80.42,
                'luckyStrikeApplied'  => false,
            ];
        }

        return (string) json_encode([
            'version'    => 2,
            'biome'      => null,
            'characters' => [
                'attacker' => ['id' => $attId, 'name' => $att],
                'defender' => ['id' => $defId, 'name' => $def],
            ],
            'rounds'  => $r,
            'outcome' => ['type' => 'normal'],
        ], JSON_UNESCAPED_UNICODE);
    }
}
