<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Onboarding\ColdOpenSignalService;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Telegram\UpdatePipeline;
use App\Services\Web\AccountService;
use App\Services\Web\WebActService;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Filters;
use Config\Services;

/** Мост не должен идти: ловим любой `act()`; конвейер не запускается. */
final class FirstScreenCountingAct extends WebActService
{
    /** @var list<array<string, mixed>> */
    public array $acts = [];

    public function act(int $accountId, int $characterId, array $intent): array
    {
        $this->acts[] = $intent;

        return parent::act($accountId, $characterId, $intent);
    }

    protected function runPipeline(UpdatePipeline $pipeline, array $update): bool
    {
        return true;
    }
}

/**
 * web-onboarding-04 — создание персонажа на сайте кладёт веб-модель первого экрана в `web_play_state`
 * (с доком), редирект ведёт в `/play`, бутстрап-мост на первом `GET /play` не идёт, тап «первый шаг»
 * принимается экраном, в тексте нет бот-лексики. Персонаж создаётся настоящим `POST account/character`.
 *
 * @internal
 */
final class WebFirstScreenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2024-04-23-070627_CreateWorldObjectsTable',
        '2024-04-23-113858_CreateBiomeWorldObjectMap',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
        '2026-12-11-100001_CreateWebPlayTables',
        '2026-12-11-100005_SignedTelegramIdColumns',
    ];

    private const RAW_TABLES = [
        'character_resources' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, quantity INT NULL, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'npcs'                => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, level INT NULL, health DECIMAL(10,2) NULL, ai_behavior VARCHAR(32) NULL, npc_type VARCHAR(32) NULL, is_boss TINYINT NOT NULL DEFAULT 0, offers_quest_title_en VARCHAR(191) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'npc_spawns'          => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, npc_id INT NOT NULL, cell_number INT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, current_health DECIMAL(10,2) NULL, spawned_at DATETIME NULL, status VARCHAR(50) NOT NULL',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'action_log', 'tasks', 'character_tasks', 'world_objects', 'biome_world_object_map', 'quests', 'quest_steps',
        'game_settings', 'character_resources', 'npcs', 'npc_spawns',
        'web_play_state', 'web_inbox', 'web_play_intents',
    ];

    private const BOT_LEXICON = '/ниже|нижн\w* меню|команд\w* \/|\/start|\/menu|клавиатур/iu';

    private BaseConnection $conn;

    /** @var array<string, mixed> */
    private array $filtersBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            foreach (self::RAW_TABLES as $t => $cols) {
                $this->conn->query("CREATE TABLE `{$t}` ({$cols}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        }
        $filters             = config(Filters::class);
        $this->filtersBackup = $filters->globals;
        unset($filters->globals['before']['csrf']);
        $this->mockSession();
        $this->mockCache();
        Services::resetSingle('throttler');
        service('superglobals')->unsetCookie('ci_session');
        $this->flag('web_open_registration', true);
    }

    protected function tearDown(): void
    {
        config(Filters::class)->globals = $this->filtersBackup;
        service('superglobals')->unsetCookie('ci_session');
        $this->dropTables();
        Factories::reset('config');
        Services::resetSingle('throttler');
        Services::resetSingle('cache');
        parent::tearDown();
    }

    public function testCreateSeedsWebFirstScreenWithDockAndRedirectsToPlay(): void
    {
        $this->flag('web_play_enabled', true);
        [, $charId] = $this->register('Web_Hero');

        $store = new WebScreenStore($this->conn);
        $state = $store->state($charId);
        $this->assertNotSame([], $state['screen']);
        $this->assertSame(1, $store->revision($charId));
        $this->assertNull($store->screenTaskId($charId));
        $this->assertSame([], $state['history']);
        $this->assertNotSame([], $state['dock'], 'док как у бутстрапа: ряды reply-меню');

        $all = '';
        foreach ($state['screen'] as $msg) {
            $this->assertNull($msg['photo_url']);
            $this->assertNull($msg['caption']);
            $this->assertNotSame('', (string) $msg['text']);
            $all .= $msg['text'] . "\n";
        }
        $last = $state['screen'][count($state['screen']) - 1];
        $this->assertSame([[['text' => '🧭 Сделать первый шаг', 'callback_data' => 'move']]], $last['inline_keyboard']);
        $this->assertStringContainsString('Роби', $all);
        $this->assertDoesNotMatchRegularExpression(self::BOT_LEXICON, $all);
    }

    public function testRedirectIsAccountWhenPlayDisabled(): void
    {
        $this->flag('web_play_enabled', false);
        $this->register('Web_Hero2', '/account');
    }

    public function testFirstPlayDoesNotRunBootstrapBridgeAndTapIsAccepted(): void
    {
        $this->flag('web_play_enabled', true);
        [$accountId, $charId] = $this->register('Web_Hero3');

        $svc    = new FirstScreenCountingAct($this->conn);
        $result = $svc->bootstrap($accountId, $charId);
        $this->assertSame([], $svc->acts, 'мост /start не идёт');
        $this->assertNotSame([], $result['state']['screen']);

        $screen = $result['state']['screen'];
        $msg    = $screen[count($screen) - 1];
        $svc->act($accountId, $charId, [
            'intent_id'  => 'tap-1',
            'kind'       => WebActService::KIND_CALLBACK,
            'message_id' => $msg['message_id'],
            'data'       => 'move',
        ]);
        $this->assertCount(1, $svc->acts, 'тап по кнопке засеянного сообщения принят');
    }

    public function testSeedIsOnceAndSeedFailureDoesNotBreakCreation(): void
    {
        $this->flag('web_play_enabled', true);
        [, $charId] = $this->register('Web_Hero4');
        $store = new WebScreenStore($this->conn);
        $rev   = $store->revision($charId);
        $this->assertFalse($store->seedFirstScreen($charId, [['text' => 'x', 'parse_mode' => null, 'inline_keyboard' => []]]));
        $this->assertSame($rev, $store->revision($charId));
        $this->assertNotSame('x', $store->state($charId)['screen'][0]['text']);

        // Сбой засева: таблицы состояния нет — персонаж всё равно создан, редирект прежний.
        $this->conn->query('DROP TABLE web_play_state');
        [$accountId] = $this->register('Web_Hero5');
        $this->assertSame(1, $this->conn->table('characters')->where('account_id', $accountId)->countAllResults());
    }

    /** @return array{0:int, 1:int} аккаунт, персонаж */
    private function register(string $name, string $to = '/play'): array
    {
        $accountId = (new AccountService($this->conn))->createAccount('web');
        $result    = $this->withSession(['account_id' => $accountId])->post('account/character', ['name' => $name]);
        $result->assertRedirectTo($to);
        $row = $this->conn->table('characters')->where('account_id', $accountId)->get()->getRowArray();
        $this->assertIsArray($row);

        return [$accountId, (int) $row['id']];
    }

    private function flag(string $key, bool $on): void
    {
        cache()->save('game_settings_' . $key, ['v' => $on, 't' => 'bool'], 60);
    }

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        for ($b = 1; $b <= 9; $b++) {
            $this->conn->table('biomes')->insert([
                'id' => $b, 'name' => "Биом {$b}", 'danger_level' => 1, 'survival_difficulty' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([[500, 900], [500, 899], [500, 897]] as [$x, $y]) {
            $n = $y * 1000 + $x + 1;
            $this->conn->table('map')->insert([
                'id' => $n, 'cell_number' => $n, 'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->conn->table('quests')->insert([
            'title_ru' => 'Первые шаги', 'title_en' => OnboardingChainCatalog::ROOT, 'description' => '', 'status' => 'active',
        ]);
        $this->conn->table('world_objects')->insert(['name' => 'Тайник', 'name_en' => ColdOpenSignalService::BAIT_NAME_EN, 'description' => '']);
        $this->conn->table('npcs')->insert([
            'name' => 'Мусорщик', 'level' => 1, 'health' => 50, 'ai_behavior' => 'passive', 'npc_type' => 'neutral', 'is_boss' => 0,
        ]);
        foreach ([
            'onboarding.quest_chain.enabled', 'onboarding.starter_kit.enabled',
            'onboarding.cold_open_v2.signal_hook', 'npc.newbie_zone.greeter_enabled', 'onboarding.cold_open_v2.single_screen',
        ] as $key) {
            $this->conn->table('game_settings')->insert([
                'setting_key' => $key, 'category' => 'experimental', 'value_type' => 'bool', 'value_bool' => 1,
            ]);
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
