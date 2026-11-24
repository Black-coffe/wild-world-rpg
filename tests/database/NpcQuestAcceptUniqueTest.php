<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\NpcModel;
use App\Services\NPC\NpcInteractionService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-1124 S4 (ask 1) — приём квеста у NPC (`NpcInteractionService::acceptOfferedQuest()`)
 * пишет `quest_steps` через тот же шаг, что и старт квеста ({@see \App\Services\Quest\QuestStartService::claimFirstStep()}):
 * строка пары, вклинившаяся между чтением-гейтом «уже начат?» и вставкой (двойной тап, бот и веб
 * одновременно), даёт отказ «задание недоступно» — без исключения `UNIQUE (quest_id, character_id)`
 * и без второй строки.
 *
 * Вклинивание — как в `QuestStepsUniqueStartTest`: слушатель `DBQuery` ловит первое чтение
 * `quest_steps` (гейт `offeredQuestFor()`), и второе соединение пишет ту же пару. Схема — прогоном
 * миграций, индекс S1 — `up()` миграции `S1QuestStepsUnique`. NPC — заглушка модели: от NPC нужен
 * только `offers_quest_title_en`.
 *
 * @internal
 */
final class NpcQuestAcceptUniqueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

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
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-12-25-100010_S1QuestStepsUnique',
    ];

    private const TABLES = ['biomes', 'map', 'telegram_users', 'characters', 'quests', 'quest_steps', 'game_settings'];

    private const NPC = 42;

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
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
            $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000042, 'Тест')");
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
        $this->setFlag('quests.extended_enabled');
        $this->setFlag('npc.questgiver_enabled');
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

    public function testRowWedgedBetweenTheGateAndTheInsertRefusesTheAcceptWithOneRow(): void
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
                    . " VALUES (1, 1, 1, 'параллельный приём', 0, ?, ?)",
                    [$now, $now]
                );
                $other->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        };
        Events::on('DBQuery', $this->listener);

        $result = null;
        $error  = null;
        try {
            $result = $this->service()->acceptOfferedQuest(1, self::NPC);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertTrue($fired, 'чтение-гейт «уже начат?» не встретилось — вклинивание не случилось');
        $this->assertNull($error, 'приём квеста упал исключением: ' . (string) $error);
        $this->assertSame(1, $this->steps(), 'у пары квест-персонаж не одна строка');
        $this->assertIsArray($result);
        $this->assertFalse($result['ok'], 'второй приём засчитан как новый');
        $this->assertSame(
            'параллельный приём',
            $this->other()->query('SELECT description FROM quest_steps WHERE quest_id = 1 AND character_id = 1')->getRowArray()['description'] ?? null,
            'строка первого приёма не перезаписана вторым'
        );
    }

    public function testFreshAcceptWritesOneRowAndTheSecondIsRefused(): void
    {
        $service = $this->service();

        $first = $service->acceptOfferedQuest(1, self::NPC);
        $this->assertTrue($first['ok']);
        $this->assertSame('Запас дров', $first['title'] ?? null);
        $this->assertSame(300, $first['reward'] ?? null);
        $this->assertFalse($service->acceptOfferedQuest(1, self::NPC)['ok']);
        $this->assertSame(1, $this->steps());
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function service(): NpcInteractionService
    {
        $npcs = new class () extends NpcModel {
            /** @param mixed $id */
            public function find($id = null)
            {
                return ['id' => $id, 'offers_quest_title_en' => 'CollectWood'];
            }
        };

        return new NpcInteractionService(null, null, $npcs);
    }

    private function setFlag(string $key): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'world', 'value_type' => 'bool', 'value_bool' => 1,
            'default_value_text' => '0', 'rationale_text' => 't', 'effect_text' => 't', 'above_effect_text' => 't',
            'below_effect_text' => 't', 'created_at' => $now, 'updated_at' => $now,
        ]);
        service('cache')->clean();
    }

    private function steps(): int
    {
        return (int) $this->other()->query(
            'SELECT COUNT(*) AS n FROM quest_steps WHERE character_id = 1 AND quest_id = 1'
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
