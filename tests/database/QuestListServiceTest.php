<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Quest\QuestListService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n5-deeds-02 — списки квестов в ядре {@see QuestListService}: без `chat_id` и Markdown, флаги
 * (`quests.extended_enabled` / `quests.faction_quests_enabled` / `quests.branching_enabled`) дают те же
 * разделы, что экран бота (классификация общая — `QuestOverviewService`).
 *
 * Схема — прогоном миграций.
 *
 * @internal
 */
final class QuestListServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
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

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'quests', 'quest_steps', 'factions', 'character_factions', 'game_settings',
    ];

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
            $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 771000005, 'Тест')");
            $this->conn->query(
                'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number)'
                . " VALUES (1, 7, 'Тест', 10, 1.5, 90, 10, 0.5, 0.01, 0.01, 0, 5)"
            );
            $this->conn->query('DELETE FROM quests');
            $this->conn->query(
                'INSERT INTO quests (id, title_ru, title_en, description, status, min_level, reward, reward_type, objective_type, objective_target, objective_qty, prerequisite_quest, faction_id, branch_group, branch_label) VALUES'
                . " (1, 'Запас *дров*', 'CollectWood', 'Принеси дерево.', 'active', 5, 300, 'gold', 'collect_resource', 'wood', 10, NULL, NULL, NULL, NULL),"
                . " (2, 'Долг фракции', 'FactionDebt', 'Для своих.', 'active', 1, 100, 'gold', 'collect_resource', 'wood', 5, NULL, 2, NULL, NULL),"
                . " (3, 'Первые шаги', 'FirstSteps', 'Начни.', 'active', 1, 100, 'items', NULL, NULL, NULL, NULL, NULL, NULL, NULL),"
                . " (4, 'Тайник', 'Stash', 'Найди.', 'active', 1, 50, 'gold', NULL, NULL, NULL, 'OldPath', NULL, NULL, NULL),"
                . " (5, 'Старый путь', 'OldPath', 'Иди.', 'active', 1, 200, 'experience', NULL, NULL, NULL, NULL, NULL, NULL, NULL),"
                . " (7, 'Ветка А', 'BranchA', 'А.', 'active', 1, 1500, 'gold', 'char_level', NULL, 3, 'FirstSteps', NULL, 'g1', '🤝 Путь А')"
            );
            $this->conn->query("INSERT INTO quest_steps (quest_id, character_id, step_order, description, is_completed) VALUES (5, 1, 1, 'x', 0), (3, 1, 1, 'x', 1)");
            $this->conn->query('INSERT INTO factions (id, name) VALUES (2, ?)', ['Караван']);
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->setFlag('quests.extended_enabled', true);
        $this->setFlag('quests.chains_enabled', true);
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        $this->dropTables();
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    public function testActiveAndCompletedCarryPlainRows(): void
    {
        $lists = new QuestListService();

        $this->assertSame([[
            'id' => 5, 'title_en' => 'OldPath', 'title_ru' => 'Старый путь', 'description' => 'Иди.', 'reward' => 200, 'reward_type_ru' => 'опыт',
        ]], $lists->active(1));
        $this->assertSame('предметы', $lists->completed(1)[0]['reward_type_ru']);
        $this->assertSame([], $lists->active(99));
    }

    public function testAvailableListsStartableThenChainLocked(): void
    {
        $rows = (new QuestListService())->available(1);

        $this->assertSame(['CollectWood', 'Stash'], array_column($rows, 'title_en'));
        $this->assertFalse($rows[0]['locked']);
        $this->assertSame('Запас *дров*', $rows[0]['title_ru'], 'модель отдаёт сырой текст — экранирует рендерер');
        $this->assertTrue($rows[1]['locked']);
        $this->assertSame('после квеста «Старый путь»', $rows[1]['lock_reason']);
    }

    public function testFlagsGiveTheSameSectionsAsTheBot(): void
    {
        $lists = new QuestListService();

        $this->setFlag('quests.extended_enabled', false);
        $this->assertNotContains('CollectWood', array_column($lists->available(1), 'title_en'), 'расширенные квесты выключены');

        $this->setFlag('quests.extended_enabled', true);
        $this->setFlag('quests.faction_quests_enabled', true);
        $this->assertNotContains('FactionDebt', array_column($lists->available(1), 'title_en'), 'чужая фракция');
        $this->conn->query('INSERT INTO character_factions (character_id, faction_id) VALUES (1, 2)');
        $this->assertContains('FactionDebt', array_column($lists->available(1), 'title_en'), 'своя фракция');

        $this->assertSame([], $lists->branches(1), 'развилки выключены');
        $this->setFlag('quests.branching_enabled', true);
        $branches = $lists->branches(1);
        $this->assertCount(1, $branches);
        $this->assertSame('Первые шаги', $branches[0]['branch_point_ru']);
    }

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

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
