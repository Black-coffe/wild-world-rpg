<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Quest\QuestStartService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-02 (C7) — `quest_steps` несёт `UNIQUE (quest_id, character_id)`, и старт квеста
 * пишет первую строку через `insertUnique()`: строка, вклинившаяся между чтением-гейтом «уже
 * начат?» и вставкой, сводит второй старт к исходу `ALREADY` — без исключения и без второй строки.
 *
 * Вклинивание детерминированно: слушатель `DBQuery` ловит чтение-гейт (`FROM \`quest_steps\``) и
 * второе соединение в этот момент пишет ту же пару. Второе соединение пишет с
 * `FOREIGN_KEY_CHECKS = 0`: с проверкой внешнего ключа вставка ждала бы S-блокировку строки
 * персонажа, которую старт держит `FOR UPDATE`, — а моделируется именно писатель мимо этой
 * блокировки, которого держит только индекс.
 *
 * Схема — прогоном миграций (как `QuestStartServiceTest`), индекс S1 — `up()` миграции
 * `S1QuestStepsUnique`, не рукописным DDL.
 *
 * @internal
 */
final class QuestStepsUniqueStartTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const MSG = 'S1 atomic write violated: concurrent writers broke the one-row / no-overspend invariant';

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2026-05-21-210000_V11AddQuestPrerequisite',
        '2026-05-21-230000_V12AddQuestObjectiveColumns',
        '2026-06-04-100000_W11AddQuestBranchColumns',
        '2026-06-14-100000_AddFactionIdToQuests',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const S1_MIGRATION = '2026-12-25-100010_S1QuestStepsUnique';

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'quests', 'quest_steps', 'factions', 'character_factions', 'game_settings',
    ];

    private BaseConnection $conn;

    /** @var (callable(mixed): void)|null */
    private $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            $files = self::MIGRATIONS;
            if (is_file(APPPATH . 'Database/Migrations/' . self::S1_MIGRATION . '.php')) {
                $files[] = self::S1_MIGRATION;
            }
            foreach ($files as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000005, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query('DELETE FROM quests');
            $this->conn->query(
                'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, objective_target, objective_qty, prerequisite_quest, faction_id) VALUES'
                . " (1, 'Запас дров', 'CollectWood', 'Принеси дерево на базу.', 'active', 5, 300, 'gold', 'collect_resource', 'wood', 10, NULL, NULL)"
            );
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->setFlag('quests.extended_enabled', true);
    }

    protected function tearDown(): void
    {
        if ($this->listener !== null) {
            Events::removeListener('DBQuery', $this->listener);
            $this->listener = null;
        }
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testRowWedgedBetweenTheGateAndTheInsertMakesTheStartAlreadyWithOneRow(): void
    {
        $fired          = false;
        $this->listener = function ($query) use (&$fired): void {
            if (! $fired && $query instanceof Query && str_contains($query->getQuery(), 'FROM `quest_steps`')) {
                $fired = true;
                $other = $this->other();
                $other->query('SET FOREIGN_KEY_CHECKS = 0');
                $now = date('Y-m-d H:i:s');
                $other->query(
                    'INSERT INTO quest_steps (quest_id, character_id, step_order, description, is_completed, created_at, updated_at)'
                    . " VALUES (1, 1, 1, 'параллельный старт', 0, ?, ?)",
                    [$now, $now]
                );
                $other->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        };
        Events::on('DBQuery', $this->listener);

        $result = null;
        $error  = null;
        try {
            $result = (new QuestStartService())->start(1, 'CollectWood');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertTrue($fired, 'чтение-гейт «уже начат?» не встретилось — вклинивание не случилось');
        $this->assertSame(1, $this->steps(1, 1), self::MSG . ' — у пары квест-персонаж не одна строка');
        $this->assertNull($error, self::MSG . ' — старт упал исключением: ' . (string) $error);
        $this->assertIsArray($result);
        $this->assertFalse($result['ok'], self::MSG . ' — второй старт засчитан как новый');
        $this->assertSame(QuestStartService::ALREADY, $result['code']);
        $this->assertSame(QuestStartService::TEXT_ALREADY, $result['message']);
        $this->assertSame(
            'параллельный старт',
            $this->other()->query('SELECT description FROM quest_steps WHERE quest_id = 1 AND character_id = 1')->getRowArray()['description'] ?? null,
            'строка первого старта не перезаписана вторым'
        );
    }

    public function testFreshStartStillWritesOneRow(): void
    {
        $service = new QuestStartService();

        $this->assertSame(QuestStartService::STARTED, $service->start(1, 'CollectWood')['code']);
        $this->assertSame(QuestStartService::ALREADY, $service->start(1, 'CollectWood')['code']);
        $this->assertSame(1, $this->steps(1, 1));
    }

    public function testMigrationLeavesTheUniquePairIndexInPlace(): void
    {
        $res = $this->conn->query("SHOW INDEX FROM quest_steps WHERE Key_name = 'uq_qs_quest_character'");
        $this->assertNotFalse($res);
        $rows = $res->getResultArray();
        $this->assertCount(2, $rows, 'индекс S1 создан миграцией на две колонки');
        $this->assertSame(0, (int) $rows[0]['Non_unique'], 'индекс уникальный');
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function setFlag(string $key, bool $on): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => $on ? 1 : 0,
            'default_value_text' => '0', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ]);
        service('cache')->clean();
    }

    private function steps(int $characterId, int $questId): int
    {
        return (int) $this->other()->query(
            'SELECT COUNT(*) AS n FROM quest_steps WHERE character_id = ? AND quest_id = ?',
            [$characterId, $questId]
        )->getRowArray()['n'];
    }

    private function other(): BaseConnection
    {
        return Database::connect(null, false);
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
