<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Web\WebNativeScreenService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * W2.N3 хвосты: веб-старт крафта ({@see WebNativeScreenService::craftStart()}) на настоящем ядре.
 * Скрытый флагом рецепт — отказ без старта и списания; каждый отказ — строка `action_log` как у бота
 * (`CRAFT_<Key>` + причина); номер в очереди карточки и ответа = `position` в списке очереди `/play`.
 * Схема — из миграций, `resources`/`character_resources` — DDL-копией, как в CraftOrderServiceTest.
 *
 * @internal
 */
final class WebCraftStartTailsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2026-07-07-100000_ExtendActionLogStatusEnum',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-25-170000_V20CreateFactionProjects',
        '2026-11-24-100000_CreateCharacterDebuffs',
        '2026-12-11-100001_CreateWebPlayTables',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'factions', 'character_factions', 'faction_projects', 'character_debuffs',
        'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const CHAR    = 1;
    private const ACCOUNT = 1;

    /** Повязка: Травы/Кора/Водоросли; Уха: Рыба/Вода/Травы. */
    private const RESOURCES = [1 => 'Травы', 2 => 'Кора деревьев', 3 => 'Водоросли', 7 => 'Вода', 12 => 'Рыба'];

    private const BANDAGE_NAV = ['bench' => 'general', 'cat' => 'medicine', 'recipe' => 'Bandage'];

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
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' biome_id TEXT NULL, is_tradeable TINYINT(1) NOT NULL DEFAULT 1, type VARCHAR(255) NULL, price INT NULL, buy_price INT NULL,'
                . ' sell_price INT NULL, rarity INT NULL, level_required INT NULL, icon_text VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->mockCache();
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    /** Рыбное блюдо при выключенном флаге: ручной POST — отказ, строки задачи нет, сырьё на месте, отказ в action_log. */
    public function testHiddenRecipeIsRefusedWithoutStartOrChargeAndLogged(): void
    {
        $this->resources([12 => 30, 7 => 30, 1 => 30]);
        $before = $this->resourceRows();

        $reply = (new WebNativeScreenService())->craftStart(self::ACCOUNT, self::CHAR, 'FishSoup', 1, 'h1');

        $this->assertSame('Этот рецепт сейчас недоступен.', $reply);
        $this->assertSame(0, $this->conn->table('character_tasks')->countAllResults());
        $this->assertSame($before, $this->resourceRows(), 'сырьё не списано');
        $this->assertSame([['CRAFT_FishSoup', 'REJECTED', 'recipe_hidden | {"qty":1}']], $this->rejections());

        // Тот же рецепт при включённом флаге проходит фильтр (дальше решает ядро) — отказ именно по флагу.
        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool, category) VALUES ('cooking.fish_dishes.enabled', 'bool', 1, 'experimental')");
        service('cache')->clean();
        $this->assertNotSame('Этот рецепт сейчас недоступен.', (new WebNativeScreenService())->craftStart(self::ACCOUNT, self::CHAR, 'FishSoup', 1, 'h2'));
    }

    /** Нехватка сырья (отказ ядра) и потолок количества — строки `CRAFT_Bandage` с причиной. */
    public function testShortageAndQtyCapRefusalsAreLogged(): void
    {
        $svc = new WebNativeScreenService();

        $this->assertSame('Недостаточно ресурсов для крафта 1 шт.', $svc->craftStart(self::ACCOUNT, self::CHAR, 'Bandage', 1, 's1'));
        $log = $this->rejections();
        $this->assertCount(1, $log);
        $this->assertSame(['CRAFT_Bandage', 'REJECTED'], [$log[0][0], $log[0][1]]);
        $this->assertStringStartsWith('missing_materials | ', $log[0][2]);

        $this->resources([1 => 14, 2 => 14, 3 => 21]);
        $this->assertSame('Столько не выйдет: сейчас можно поставить не больше 7 шт.', $svc->craftStart(self::ACCOUNT, self::CHAR, 'Bandage', 8, 's2'));
        $this->assertSame(['CRAFT_Bandage', 'REJECTED', 'qty_over_max | {"qty":8,"max_qty":7}'], $this->rejections()[1]);
        $this->assertSame(0, $this->conn->table('character_tasks')->countAllResults());
    }

    /**
     * Один идущий и один ожидающий крафт Повязки: карточка и ответ — №2 (ядро бота сказало бы №3),
     * новая строка — №2 в списке очереди. Пустая очередь и свободный слот — строки «Место в очереди» нет.
     */
    public function testQueueNumberMatchesTheQueueList(): void
    {
        $this->resources([1 => 20, 2 => 20, 3 => 30]);
        $task = $this->taskId('craftBandage');
        $this->charTask($task, 'in_work');
        $this->charTask($task, 'queued');
        $svc = new WebNativeScreenService();

        $card = $svc->craftModel(self::CHAR, self::BANDAGE_NAV)['card'];
        $this->assertIsArray($card);
        $this->assertSame(2, $card['queue_pos']);
        $this->assertStringContainsString('<dt>📋 Место в очереди</dt><dd>№2</dd>', $this->html($svc));

        $this->assertSame('📋 В очереди: 🩹 Повязка ×1 — №2. Начнётся, когда закончится текущий.', $svc->craftStart(self::ACCOUNT, self::CHAR, 'Bandage', 1, 'q1'));
        $queued = $svc->craftModel(self::CHAR, [])['queue'];
        $this->assertIsArray($queued);
        $last = end($queued['queued']);
        $this->assertIsArray($last);
        $this->assertSame(2, $last['position'], 'новая строка — №2 в списке очереди');

        $this->conn->query('TRUNCATE TABLE character_tasks');
        $this->assertNull($svc->craftModel(self::CHAR, self::BANDAGE_NAV)['card']['queue_pos'] ?? null);
        $this->assertStringNotContainsString('Место в очереди', $this->html($svc));
    }

    private function html(WebNativeScreenService $svc): string
    {
        return html_entity_decode($svc->render(self::CHAR, WebNativeScreenService::VIEW_CRAFT, [], null, [], null, self::BANDAGE_NAV), ENT_QUOTES | ENT_HTML5);
    }

    /** @param array<int, int> $qty id ресурса → количество */
    private function resources(array $qty): void
    {
        $this->conn->query('TRUNCATE TABLE character_resources');
        foreach ($qty as $id => $n) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $n]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function resourceRows(): array
    {
        return $this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id_resources')->getResultArray();
    }

    /** @return list<array{0:string, 1:string, 2:string}> */
    private function rejections(): array
    {
        $out = [];
        foreach ($this->conn->query("SELECT action_name, action_status, description FROM action_log WHERE action_status = 'REJECTED' ORDER BY id")->getResultArray() as $r) {
            $out[] = [(string) $r['action_name'], (string) $r['action_status'], (string) $r['description']];
        }

        return $out;
    }

    private function charTask(int $taskId, string $status): void
    {
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings) VALUES (1, 7, ?, ?, ?, ?, ?)',
            [$taskId, date('Y-m-d H:i:s', time() - 30), $status === 'in_work' ? date('Y-m-d H:i:s', time() + 3570) : null, $status, json_encode(['recipe' => 'Bandage', 'quantity' => 1])]
        );
    }

    private function taskId(string $name): int
    {
        $id = $this->conn->query('SELECT id FROM tasks WHERE name = ?', [$name])->getRow('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, 555003, 'Тест')");
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1)"
        );
        foreach (self::RESOURCES as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
        }
        foreach ([['craftBandage', 'Изготовление повязки'], ['craftFishSoup', 'Уха']] as [$name, $rus]) {
            $this->conn->query("INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, 'craft', 1)", [$name, $rus]);
        }
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}
