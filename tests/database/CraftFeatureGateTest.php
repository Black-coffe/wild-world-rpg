<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use App\Services\Craft\CraftOrderService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * W2.N3 craft-flags-01 — рецепт, выключенный флагом фичи (рыбные блюда по
 * `cooking.fish_dishes.enabled`, дроны по флагам `DroneService`), не стартует прямым callback'ом
 * `genericCraft_<Key>_<qty>`: гейт ядра {@see CraftOrderService::gateError()} отказывает первым,
 * бот шлёт «Этот рецепт сейчас недоступен.» и пишет отказ в `action_log`; ни строки задачи, ни
 * списания. При включённом флаге — старт как раньше.
 *
 * Отдельный процесс на тест — как паритет бота в {@see CraftOrderServiceTest}: соседние тесты
 * определяют `PHPUNIT_TESTSUITE`, и Longman под ним не доходит до HTTP-клиента.
 *
 * @internal
 */
final class CraftFeatureGateTest extends CIUnitTestCase
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
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'factions', 'character_factions', 'faction_projects', 'character_debuffs',
    ];

    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    private const TG = 555003;

    private const REFUSAL = 'Этот рецепт сейчас недоступен.';

    private BaseConnection $conn;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

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
            // CreateResourcesTable не идёт на MySQL 8 (TEXT UNSIGNED) — DDL-копия, как в CraftOrderServiceTest.
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
        foreach (self::ENV as $k => $v) {
            $this->envBackup[$k] = getenv($k);
            putenv("{$k}={$v}");
        }
        $this->mockCache();
        service('cache')->clean();
        $this->installRecorder();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
        $this->dropTables();
        parent::tearDown();
    }

    /** Уха при выключенном флаге рыбных блюд: отказ ботом, лог, без записи; при включённом — старт. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFishDishOffIsRefusedAndOnStarts(): void
    {
        $this->flag('cooking.fish_dishes.enabled', false);
        $this->assertRefused('FishSoup');

        $this->flag('cooking.fish_dishes.enabled', true);
        $this->assertStarts('FishSoup', 'craftFishSoup');
    }

    /** Дрон-разведчик при выключенном `drone.scout.enabled`: тот же отказ; при включённом — старт как раньше. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDroneOffIsRefusedAndOnStarts(): void
    {
        $this->seedDroneScout();
        $this->flag('drone.scout.enabled', false);
        $this->assertRefused('DroneScout');

        $this->flag('drone.scout.enabled', true);
        $this->assertStarts('DroneScout', 'craftDroneScout');
    }

    /** Превью ядра отдаёт тот же код отказа — веб берёт ту же проверку. */
    public function testPreviewRefusesFlaggedRecipe(): void
    {
        $this->flag('cooking.fish_dishes.enabled', false);
        $p = (new CraftOrderService())->preview(1, 'FishSoup', 1);
        $this->assertSame([false, CraftOrderService::FEATURE_OFF, self::REFUSAL], [$p['ok'], $p['code'], $p['message']]);
        $this->assertFalse((new CraftOrderService())->recipeEnabled('FishSoup'));
        $this->assertTrue((new CraftOrderService())->recipeEnabled('Bandage'));
    }

    private function assertRefused(string $key): void
    {
        $before = $this->state();
        $this->press("genericCraft_{$key}_1");

        $texts = array_values(array_filter(array_map(static fn (array $r): mixed => $r['text'] ?? null, $this->sent)));
        $this->assertSame([self::REFUSAL], $texts, "{$key}: текст отказа");
        $this->assertSame([], $this->rows(), "{$key}: нет строки character_tasks");
        $after = $this->state();
        $this->assertSame($before['resources'], $after['resources'], "{$key}: сырьё не списано");
        $this->assertSame($before['items'], $after['items'], "{$key}: компоненты не списаны");
        $this->assertSame($before['gold'], $after['gold'], "{$key}: золото не списано");

        $log = $this->conn->query('SELECT action_name, action_status, description FROM action_log ORDER BY id')->getResultArray();
        $this->assertCount(1, $log);
        // action_status не сверяем: ENUM исходной миграции не знает REJECTED (как в снимках CraftOrderServiceTest).
        $this->assertSame("CRAFT_{$key}", $log[0]['action_name']);
        $this->assertStringStartsWith('recipe_feature_disabled', (string) $log[0]['description']);
        $this->conn->query('TRUNCATE TABLE action_log');
    }

    private function assertStarts(string $key, string $task): void
    {
        $before = $this->state();
        $this->photoWrapper();
        try {
            $this->press("genericCraft_{$key}_1");
        } finally {
            stream_wrapper_restore('http');
        }

        $texts = implode("\n", array_filter(array_map(static fn (array $r): string => is_string($r['text'] ?? null) ? $r['text'] : '', $this->sent)));
        $this->assertStringContainsString('Процесс крафта запущен', $texts, "{$key}: старт");
        $this->assertSame([[$task, 'in_work']], array_map(static fn (array $r): array => [$r['task'], $r['status']], $this->rows()));
        $this->assertNotSame($before['resources'], $this->state()['resources'], "{$key}: сырьё списано");
    }

    private function flag(string $key, bool $on): void
    {
        $this->conn->query('DELETE FROM game_settings WHERE setting_key = ?', [$key]);
        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool, category) VALUES (?, 'bool', ?, 'craft')", [$key, $on ? 1 : 0]);
        service('cache')->clean();
    }

    /** @return list<array{task:string, status:string}> */
    private function rows(): array
    {
        $rows = $this->conn->query('SELECT t.name AS task, ct.status FROM character_tasks ct LEFT JOIN tasks t ON t.id = ct.task_id ORDER BY ct.id')->getResultArray();

        return array_map(static fn (array $r): array => ['task' => (string) $r['task'], 'status' => (string) $r['status']], $rows);
    }

    /** @return array{resources:list<array<string,mixed>>, items:list<array<string,mixed>>, gold:mixed} */
    private function state(): array
    {
        return [
            'resources' => array_values($this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id_resources')->getResultArray()),
            'items'     => array_values($this->conn->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray()),
            'gold'      => $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRow('gold'),
        ];
    }

    private function press(string $data): void
    {
        $this->sent = [];
        $cb         = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 77, 'date' => 0, 'chat' => ['id' => self::TG, 'type' => 'private']],
            'data'    => $data,
        ]);
        (new GenericCraftActionStart($cb))->handle();
    }

    /** Фото в media-off не уходит, но handler открывает поток по URL — отдаём пустое тело вместо сети. */
    private function photoWrapper(): void
    {
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', CraftFeatureGatePhotoStream::class);
    }

    private function installRecorder(): void
    {
        $this->sent = [];
        new Telegram(self::ENV['telegram.API_KEY'], self::ENV['telegram.BOT_USERNAME']);
        $client = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path         = explode('/', $request->getUri()->getPath());
            $this->sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        LongmanRequest::setClient($client);
    }

    /** Персонаж (media off) и сырьё ухи: Рыба/Вода/Травы. */
    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, 'Тест')", [self::TG]);
        $this->conn->query(
            "INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)"
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 10000, 5, 1)"
        );
        foreach ([1 => 'Рыба', 2 => 'Вода', 3 => 'Травы', 4 => 'Янтарь', 5 => 'Смола деревьев'] as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, 50)', [$id]);
        }
        foreach ([['craftFishSoup', 'Готовка ухи'], ['craftDroneScout', 'Сборка дрона-разведчика']] as [$name, $rus]) {
            $this->conn->query("INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, 'craft', 1)", [$name, $rus]);
        }
    }

    /** Дрон-разведчик: база, Мастерская робототехники L1, компоненты. */
    private function seedDroneScout(): void
    {
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')");
        $this->conn->query(
            "INSERT INTO buildings (id, name_ru, name_en, building_type, hp, construction_time, tax, level, `usage`, min_character_level)"
            . " VALUES (1, 'Мастерская робототехники', 'RoboticsWorkshop', 'engineering', 100, 10, 0, 1, 'personal', 1)"
        );
        $this->conn->query(
            'INSERT INTO character_buildings (character_id, building_id, map_cell_id, character_level_during_construction, hp, level, built_at, building_type, tax, `usage`)'
            . " VALUES (1, 1, 5, 5, 100, 1, NOW(), 'engineering', 0, 'personal')"
        );
        $this->conn->query(
            "INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (1, 'Стеклянные колбы', 'GlassBags', 'component'),"
            . " (2, 'Ткань', 'Fabric', 'component'), (3, 'Металлические фрагменты', 'metalFragments', 'component')"
        );
        foreach ([1 => 10, 2 => 20, 3 => 60] as $id => $qty) {
            $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, ?, 'component', ?)", [$id, $qty]);
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

/** Поток-заглушка для `http://`: пустое тело вместо сети. */
final class CraftFeatureGatePhotoStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
