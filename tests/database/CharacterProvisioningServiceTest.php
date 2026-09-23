<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Onboarding\ColdOpenSignalService;
use App\Services\Onboarding\NewbieGreeterService;
use App\Services\Onboarding\OnboardingChainCatalog;
use App\Services\Onboarding\StarterKitService;
use App\Services\Player\CharacterProvisioningService;
use App\Services\Web\AccountService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * web-accounts-p0-04 (ADR-188) — создание персонажа вынесено из `/start` в
 * {@see CharacterProvisioningService}.
 *
 * Ask 14: бот-путь пишет те же строки, что и `StartCommand` до рефактора — эталон
 * {@see PRE_REFACTOR_CHARACTER} списан со вставки `StartCommand.php:91-114` (коммит 69708f3f),
 * спавн — одна из суши Y ≥ 900, цепочка, паёк + флаг в action_log с chat id, приманка,
 * встречающий. Ask 1: персонаж бота получает аккаунт с telegram-identity. Ask 5: персонаж
 * без Telegram играбелен, флаги пишутся с `chat_id = NULL`, строки `telegram_users` нет.
 *
 * Схема — исполнением настоящих миграций; рукописное DDL — только для таблиц без исполнимой
 * миграции (`character_resources` зависит от `CreateResourcesTable`, не идущей на MySQL 8;
 * `npcs`/`npc_spawns` миграций в репозитории не имеют). Все четыре killswitch'а включены
 * строками `game_settings`.
 *
 * @internal
 */
final class CharacterProvisioningServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAT_ID = 555000222;

    /** Эталон строки `characters` из pre-refactor `StartCommand` (то, что писал insert). */
    private const PRE_REFACTOR_CHARACTER = [
        'level' => 1, 'experience' => 0.01, 'health' => 100, 'tired' => 100,
        'strength' => 0.01, 'agility' => 0.01, 'intellect' => 0.01, 'gold' => 1000,
    ];

    /** Паёк по умолчанию (StarterKitService::DEFAULTS): resource_id → количество. */
    private const KIT = [17 => 10, 2 => 10, 8 => 8, 19 => 8, 3 => 4, 20 => 6];

    /** Единственная допустимая клетка спавна (кромка Y = 900); приманка (3 к северу) и встречающий (1 к северу) лежат уже вне полосы спавна. */
    private const SPAWN = [500, 900];

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-04-23-070627_CreateWorldObjectsTable',
        '2024-04-23-113858_CreateBiomeWorldObjectMap',
        '2024-04-26-121416_CreateQuestsTable',
        '2024-04-26-192334_CreateQuestStepsTable',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-12-10-100001_CreateAccountsTables',
        '2026-12-10-100002_LinkCharactersToAccounts',
        '2026-12-10-100010_NullableTelegramKeys',
    ];

    private const RAW_TABLES = [
        'character_resources' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT NULL, id_resources INT NULL, quantity INT NULL, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'npcs'                => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, level INT NULL, health DECIMAL(10,2) NULL, ai_behavior VARCHAR(32) NULL, npc_type VARCHAR(32) NULL, is_boss TINYINT NOT NULL DEFAULT 0, offers_quest_title_en VARCHAR(191) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'npc_spawns'          => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, npc_id INT NOT NULL, cell_number INT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL, current_health DECIMAL(10,2) NULL, spawned_at DATETIME NULL, status VARCHAR(50) NOT NULL',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'accounts', 'account_identities', 'account_tokens', 'account_link_codes',
        'characters', 'action_log', 'world_objects', 'biome_world_object_map', 'quests', 'quest_steps',
        'game_settings', 'character_resources', 'npcs', 'npc_spawns',
    ];

    private BaseConnection $conn;
    private int $rootQuestId = 0;
    private int $npcId = 0;
    private int $anchorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->cleanCache();
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
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->cleanCache();
        parent::tearDown();
    }

    public function testBotCreateWritesPreRefactorRowsAndTelegramAccount(): void
    {
        $tgUserId = $this->makeTelegramUser(self::CHAT_ID);
        $svc      = new CharacterProvisioningService();

        $charId = $svc->create('tg_hero', $tgUserId, self::CHAT_ID, null);

        $char = $this->row('characters', ['id' => $charId]);
        $this->assertSame('tg_hero', $char['name']);
        $this->assertSame($tgUserId, (int) $char['telegram_user_id']);
        $this->assertCharacterMatchesFixture($char);
        $this->assertSpawnedInNewbieZone($char);
        $this->assertOnboardingWrites($charId, self::CHAT_ID);

        // Ask 1: аккаунт с telegram-identity — та же форма, что у backfill story 01.
        $accountId = (int) $char['account_id'];
        $this->assertGreaterThan(0, $accountId);
        $identity = $this->row('account_identities', ['account_id' => $accountId]);
        $this->assertSame('telegram', $identity['provider']);
        $this->assertSame((string) self::CHAT_ID, $identity['subject']);
        $this->assertSame(1, $this->rowCount('account_identities', []));

        $texts = $svc->lastTexts();
        $this->assertTrue($texts['spawned']);
        $this->assertNotNull($texts['starterKit']);
        $this->assertNotNull($texts['signal']);
        $this->assertNotNull($texts['greeter']);
    }

    public function testWebOnlyCreateIsPlayableWithoutTelegram(): void
    {
        $accountId = (new AccountService())->createAccount('web');

        $charId = (new CharacterProvisioningService())->create('Имя', null, null, $accountId);

        $char = $this->row('characters', ['id' => $charId]);
        $this->assertSame('Имя', $char['name']);
        $this->assertNull($char['telegram_user_id']);
        $this->assertSame($accountId, (int) $char['account_id']);
        $this->assertCharacterMatchesFixture($char);
        $this->assertSpawnedInNewbieZone($char);
        $this->assertOnboardingWrites($charId, null);
        $this->assertSame(0, $this->rowCount('telegram_users', []));
        $this->assertSame(0, $this->rowCount('account_identities', []));
    }

    public function testEmptyNameFallsBackToTravellerForBothCallers(): void
    {
        $svc = new CharacterProvisioningService();

        $botChar = $svc->create('', $this->makeTelegramUser(self::CHAT_ID), self::CHAT_ID, null);
        $webChar = $svc->create('', null, null, (new AccountService())->createAccount('web'));

        $this->assertSame('Путник-' . $botChar, $this->row('characters', ['id' => $botChar])['name']);
        $this->assertSame('Путник-' . $webChar, $this->row('characters', ['id' => $webChar])['name']);
    }

    // ── Проверки ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $char */
    private function assertCharacterMatchesFixture(array $char): void
    {
        foreach (self::PRE_REFACTOR_CHARACTER as $col => $expected) {
            $this->assertEqualsWithDelta((float) $expected, (float) $char[$col], 0.00001, "characters.{$col}");
        }
    }

    /** @param array<string, mixed> $char */
    private function assertSpawnedInNewbieZone(array $char): void
    {
        $this->assertNotNull($char['cell_number']);
        $cell = $this->row('map', ['cell_number' => (int) $char['cell_number']]);
        $this->assertGreaterThanOrEqual(900, (int) $cell['coordinate_y']);
        $this->assertContains((int) $cell['biome_id'], [1, 2, 3, 5, 6, 7, 8, 9]);
        $this->assertSame(self::SPAWN, [(int) $cell['coordinate_x'], (int) $cell['coordinate_y']]);
    }

    private function assertOnboardingWrites(int $charId, ?int $chatId): void
    {
        // Обучающая цепочка — первый шаг корня.
        $step = $this->row('quest_steps', ['character_id' => $charId]);
        $this->assertSame($this->rootQuestId, (int) $step['quest_id']);
        $this->assertSame(1, (int) $step['step_order']);

        // Паёк: все шесть ресурсов с количествами по умолчанию + флаг с chat id.
        foreach (self::KIT as $resId => $qty) {
            $this->assertSame($qty, (int) $this->row('character_resources', ['id_characters' => $charId, 'id_resources' => $resId])['quantity']);
        }
        $this->assertSame(count(self::KIT), $this->rowCount('character_resources', ['id_characters' => $charId]));
        $kitFlag = $this->row('action_log', ['character_id' => $charId, 'action_name' => StarterKitService::GRANTED_FLAG]);
        $this->assertSame($chatId, $kitFlag['chat_id'] === null ? null : (int) $kitFlag['chat_id']);

        // Приманка cold-open — 3 клетки к северу от спавна.
        $bait = $this->row('biome_world_object_map', ['world_object_id' => $this->anchorId]);
        $this->assertSame($this->cellNumber(self::SPAWN[0], self::SPAWN[1] - 3), (int) $bait['map_id']);
        $this->assertSame('active', $bait['status']);

        // Встречающий — 1 клетка к северу, маркер с chat id.
        $spawn = $this->row('npc_spawns', ['npc_id' => $this->npcId, 'cell_number' => $this->cellNumber(self::SPAWN[0], self::SPAWN[1] - 1)]);
        $this->assertSame('alive', $spawn['status']);
        $marker = $this->row('action_log', ['character_id' => $charId, 'action_name' => NewbieGreeterService::PLACED_FLAG]);
        $this->assertSame($chatId, $marker['chat_id'] === null ? null : (int) $marker['chat_id']);
    }

    // ── Фикстура ─────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        for ($b = 1; $b <= 9; $b++) {
            $this->conn->table('biomes')->insert([
                'id' => $b, 'name' => "Биом {$b}", 'danger_level' => 1, 'survival_difficulty' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        [$sx, $sy] = self::SPAWN;
        $cells = [
            [$sx, $sy, 1],       // единственная допустимая клетка спавна
            [$sx, $sy - 1, 1],   // встречающий (distance 1, север)
            [$sx, $sy - 3, 1],   // приманка (distance 3, север)
            [$sx + 5, $sy, 4],   // вода в новичковой полосе — не спавн
            [$sx, 100, 1],       // суша вне полосы Y ≥ 900 — не спавн
        ];
        foreach ($cells as [$x, $y, $biome]) {
            $n = $this->cellNumber($x, $y);
            $this->conn->table('map')->insert([
                'id' => $n, 'cell_number' => $n, 'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => $biome,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->conn->table('quests')->insert([
            'title_ru' => 'Первые шаги', 'title_en' => OnboardingChainCatalog::ROOT, 'description' => '', 'status' => 'active',
        ]);
        $this->rootQuestId = (int) $this->conn->insertID();

        $this->conn->table('world_objects')->insert(['name' => 'Тайник', 'name_en' => ColdOpenSignalService::BAIT_NAME_EN, 'description' => '']);
        $this->anchorId = (int) $this->conn->insertID();

        $this->conn->table('npcs')->insert([
            'name' => 'Мусорщик', 'level' => 1, 'health' => 50, 'ai_behavior' => 'passive', 'npc_type' => 'neutral', 'is_boss' => 0,
        ]);
        $this->npcId = (int) $this->conn->insertID();

        foreach ([
            'onboarding.quest_chain.enabled', 'onboarding.starter_kit.enabled',
            'onboarding.cold_open_v2.signal_hook', 'npc.newbie_zone.greeter_enabled',
        ] as $key) {
            $this->conn->table('game_settings')->insert([
                'setting_key' => $key, 'category' => 'experimental', 'value_type' => 'bool', 'value_bool' => 1,
            ]);
        }
    }

    private function makeTelegramUser(int $telegramId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->table('telegram_users')->insert([
            'telegram_id' => $telegramId, 'username' => 'tg', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->conn->insertID();
    }

    private function cellNumber(int $x, int $y): int
    {
        return $y * 1000 + $x + 1;
    }

    /**
     * @param array<string, int|string> $where
     *
     * @return array<string, mixed>
     */
    private function row(string $table, array $where): array
    {
        $row = $this->conn->table($table)->where($where)->get()->getRowArray();
        $this->assertIsArray($row, "{$table}: строка не найдена");

        return $row;
    }

    /** @param array<string, int|string> $where */
    private function rowCount(string $table, array $where): int
    {
        return $this->conn->table($table)->where($where)->countAllResults();
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

    private function cleanCache(): void
    {
        $c = cache();
        if (is_object($c) && method_exists($c, 'clean')) {
            $c->clean();
        }
    }
}
