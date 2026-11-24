<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\OnboardingChainService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * s1-atomicity-1124 S6 (ask 1) — третий писатель `quest_steps`: назначение первого шага
 * онбординг-цепочки ({@see OnboardingChainService::ensureChainAssigned()}, /start и создание
 * персонажа). Строка пары, вклинившаяся между чтением-гейтом `hasStep()` и вставкой (два /start
 * подряд, бот и веб одновременно), делает назначение no-op: одна строка, без исключения
 * `UNIQUE (quest_id, character_id)`, и метод не говорит «шаг только что назначен».
 *
 * Вклинивание — как в `NpcQuestAcceptUniqueTest`: слушатель `DBQuery` ловит первое чтение
 * `quest_steps` (гейт `hasStep()`), и второе соединение пишет ту же пару. Схема — прогоном
 * миграций, индекс S1 — `up()` миграции `S1QuestStepsUnique`.
 *
 * @internal
 */
final class OnboardingChainInsertStepUniqueTest extends CIUnitTestCase
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
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000043, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
                . " VALUES (1, 7, 'Тест', 1, 0, 100, 0, 0.5, 0.01, 0.01, 0, 5, 0)"
            );
            $this->conn->query('DELETE FROM quests');
            $this->conn->query(
                'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, objective_target, objective_qty, prerequisite_quest, faction_id) VALUES'
                . " (1, 'Первый шаг', ?, 'Сделай шаг.', 'active', 1, 10, 'gold', 'explore_cells', NULL, 1, NULL, NULL)",
                [OnboardingChainCatalog::ROOT]
            );
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->setFlag('onboarding.quest_chain.enabled');
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

    public function testRowWedgedBetweenHasStepAndTheInsertIsANoOpWithOneRow(): void
    {
        $fired          = false;
        $this->listener = function ($query) use (&$fired): void {
            if (! $fired && $query instanceof Query && str_contains($query->getQuery(), 'FROM `quest_steps`')) {
                $fired = true;
                $now   = date('Y-m-d H:i:s');
                $this->other()->query(
                    'INSERT INTO quest_steps (quest_id, character_id, step_order, description, is_completed, created_at, updated_at)'
                    . " VALUES (1, 1, 1, 'параллельный /start', 0, ?, ?)",
                    [$now, $now]
                );
            }
        };
        Events::on('DBQuery', $this->listener);

        $assigned = null;
        $error    = null;
        try {
            $assigned = (new OnboardingChainService())->ensureChainAssigned(['id' => 1, 'level' => 1]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertTrue($fired, 'чтение-гейт hasStep() не встретилось — вклинивание не случилось');
        $this->assertNull($error, 'назначение шага упало исключением: ' . (string) $error);
        $this->assertSame(1, $this->steps(), 'у пары квест-персонаж не одна строка');
        $this->assertFalse($assigned, 'вклинившийся шаг засчитан как только что назначенный');
        $this->assertSame(
            'параллельный /start',
            $this->other()->query('SELECT description FROM quest_steps WHERE quest_id = 1 AND character_id = 1')->getRowArray()['description'] ?? null,
            'строка первого назначения перезаписана вторым'
        );
    }

    public function testFreshAssignWritesOneRowAndTheSecondIsANoOp(): void
    {
        $service = new OnboardingChainService();

        $this->assertTrue($service->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertFalse($service->ensureChainAssigned(['id' => 1, 'level' => 1]));
        $this->assertSame(1, $this->steps());
        $row = $this->other()->query(
            'SELECT step_order, description, is_completed, created_at FROM quest_steps WHERE quest_id = 1 AND character_id = 1'
        )->getRowArray();
        $this->assertIsArray($row);
        $this->assertSame('1', (string) $row['step_order']);
        $this->assertSame(OnboardingChainCatalog::steps()[0]['title_ru'], $row['description']);
        $this->assertSame('0', (string) $row['is_completed']);
        $this->assertNotNull($row['created_at'], 'вставка потеряла метку времени модели');
    }

    // ── помощники ────────────────────────────────────────────────────────────

    private function setFlag(string $key): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $now = date('Y-m-d H:i:s');
        $this->conn->table('game_settings')->insert([
            'setting_key' => $key, 'category' => 'experimental', 'value_type' => 'bool', 'value_bool' => 1,
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
