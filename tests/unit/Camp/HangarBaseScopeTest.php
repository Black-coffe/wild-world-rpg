<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\HangarAction;
use App\Models\BuildingModel;
use App\Services\Bases\BaseScopeResolver;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * multibase-picker-04 — «Стою на второй базе, ангар на ней не построен, но кнопка
 * есть, по нажатию проваливаюсь в интерфейс ангара первой базы».
 *
 * `HangarAction` раньше брал `max(level)` Мастерской робототехники по ВСЕМ базам
 * персонажа (recon.md «Ангар и роботы», старый `workshopLevel()` :371-397). Правка
 * резолвит базу через `BaseCallbackSuffix`/`BaseScopeResolver` (`hangar_b<id>` →
 * `resolveForBase()`, `hangar` → `resolve()`) и читает Мастерскую ТОЙ базы через
 * переиспользованные `StartRobotGatheringAction::workshopAtBase()`/`baseLabel()`
 * (owned by story 05).
 *
 * Своя схема под приватным префиксом `hbs_` (паттерн `StartRobotGatheringBaseTest`).
 *
 * @internal
 */
final class HangarBaseScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'hbs_';

    /** @var array<string,string> таблица => колонки (порядок создания; дроп — в обратном). */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, disable_media TINYINT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL, camp_name VARCHAR(64) NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'tasks'               => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL',
        'character_tasks'     => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items'       => 'id INT AUTO_INCREMENT PRIMARY KEY, name_rus VARCHAR(255) NULL, name_eng VARCHAR(255) NULL, type VARCHAR(32) NULL, durability_count INT NULL, status VARCHAR(16) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'crafted_items_log'   => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, task_id INT NULL, crafted_item_id INT NULL, type VARCHAR(100) NULL, direction_craft VARCHAR(100) NULL, crafting_location VARCHAR(255) NULL, durability_count INT NULL, durability_time DATETIME NULL, quantity INT NULL, insured TINYINT NULL, custom_setting TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    private string $origPrefix = '';
    private int $workshopId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Request коротит на фейковый ServerResponse вместо HTTP (feedback_taskhandler_telegram_init_in_tests).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);
        foreach (self::TABLES as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) DEFAULT CHARSET=utf8mb4");
        }
        self::resetBuildingCache();

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }

        $this->db()->table('buildings')->insert(['name_ru' => 'Мастерская робототехники', 'name_en' => 'RoboticsWorkshop']);
        $this->workshopId = (int) $this->db()->insertID();
        $this->db()->table('map')->insertBatch([
            ['id' => 100, 'cell_number' => 100, 'coordinate_x' => 10, 'coordinate_y' => 10, 'biome_id' => 1],
            ['id' => 200, 'cell_number' => 200, 'coordinate_x' => 20, 'coordinate_y' => 20, 'biome_id' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
            self::resetBuildingCache();
        }

        parent::tearDown();
    }

    private static function resetBuildingCache(): void
    {
        $prop = new \ReflectionProperty(BuildingModel::class, 'byNameEnCache');
        $prop->setValue(null, []);
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedPlayer(int $standingOn): array
    {
        $tgId = random_int(750_000_000, 759_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert(['telegram_user_id' => $tgUid, 'cell_number' => $standingOn, 'locale' => 'ru']);
        $charId = (int) $this->db()->insertID();

        return [$tgId, $charId];
    }

    /** @return array{0:int,1:int} [base1Id, base2Id] */
    private function seedTwoBases(int $charId): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db()->table('claimed_cells')->insert(
            ['character_id' => $charId, 'map_cell_id' => 100, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Первая']
        );
        $base1 = (int) $this->db()->insertID();
        $this->db()->table('claimed_cells')->insert(
            ['character_id' => $charId, 'map_cell_id' => 200, 'claimed_at' => $now, 'status' => 'active', 'camp_name' => 'Вторая']
        );
        $base2 = (int) $this->db()->insertID();

        return [$base1, $base2];
    }

    private function seedWorkshop(int $charId, int $cell, int $level): void
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $this->workshopId, 'map_cell_id' => $cell, 'level' => $level,
        ]);
    }

    private function cbq(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_' . random_int(1, 999999999),
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data' => $data,
        ]);
    }

    /**
     * `editTextOrSend()` с фейковой `CallbackQuery` (всегда несёт `message_id`)
     * уходит в ветку `editMessageText` — текст в `text`, не в `caption`.
     */
    private function textOf(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result)) {
            return '';
        }

        $caption = $result->getCaption();
        if (is_string($caption) && $caption !== '') {
            return $caption;
        }

        $text = $result->getText();

        return is_string($text) ? $text : '';
    }

    public function testSuffixToBaseWithoutOwnWorkshopLocksAndNamesThatBaseOnly(): void
    {
        [$tgId, $charId]  = $this->seedPlayer(200); // стоит на «Вторая»
        [, $base2]        = $this->seedTwoBases($charId);
        $this->seedWorkshop($charId, 100, 5); // Мастерская ур. 5 — ТОЛЬКО на «Первая»

        $response = (new HangarAction($this->cbq($tgId, 'hangar_b' . $base2)))->handle();
        $text     = $this->textOf($response);

        $this->assertStringContainsString('Вторая (20, 20)', $text, 'называет базу, с которой открыт (эту)');
        $this->assertStringContainsString('Мастерская робототехники на этой базе', $text);
        $this->assertStringNotContainsString('уровень 5', $text, 'уровень чужой базы не просачивается как местный');
        $this->assertStringNotContainsString('Первая', $text, 'хозяйство другой базы не показывается как местное');
    }

    public function testSuffixToOwnBaseShowsItsWorkshopLevelAndLabel(): void
    {
        [$tgId, $charId] = $this->seedPlayer(100); // стоит на «Первая»
        [$base1]         = $this->seedTwoBases($charId);
        $this->seedWorkshop($charId, 100, 5);

        $response = (new HangarAction($this->cbq($tgId, 'hangar_b' . $base1)))->handle();
        $text     = $this->textOf($response);

        $this->assertStringContainsString('🤖 *Ангар автоматизации*', $text);
        $this->assertStringContainsString('Первая (10, 10)', $text, 'называет базу и координаты');
        $this->assertStringContainsString('уровень 5', $text, 'уровень МЕСТНОЙ мастерской');
        // media-off: экран текстовый, без фото — весь смысл в тексте.
        $this->assertNull($response->getResult()->getMedia() ?? null);
    }

    public function testForeignOrOutOfSignalSuffixIsUnavailable(): void
    {
        [$tgId, $charId]  = $this->seedPlayer(999); // не на своей базе
        $this->seedTwoBases($charId);

        // Чужая база — стоящая не на ней, без сигнала вышки.
        [$otherTgId, $otherCharId] = $this->seedPlayer(500);
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $otherCharId, 'map_cell_id' => 500,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'camp_name' => 'Чужая',
        ]);
        $foreignBaseId = (int) $this->db()->insertID();

        $response = (new HangarAction($this->cbq($tgId, 'hangar_b' . $foreignBaseId)))->handle();
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));

        // Своя база, но игрок вне сигнала и не на ней.
        $ownBaseFarAway = $this->db()->table('claimed_cells')->where('character_id', $charId)->get()->getFirstRow('array');
        $this->assertIsArray($ownBaseFarAway);
        $response2 = (new HangarAction($this->cbq($tgId, 'hangar_b' . $ownBaseFarAway['id'])))->handle();
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response2));
    }

    public function testWithoutSuffixUsesLegacyResolveForSingleBaseCharacter(): void
    {
        [$tgId, $charId] = $this->seedPlayer(777); // не стоит на базе вовсе
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => 100,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'camp_name' => 'Единственная',
        ]);
        $this->seedWorkshop($charId, 100, 3);

        $response = (new HangarAction($this->cbq($tgId, 'hangar')))->handle();
        $text     = $this->textOf($response);

        $this->assertStringContainsString('Единственная (10, 10)', $text);
        $this->assertStringContainsString('уровень 3', $text);
    }

    /** @return list<string> callback_data'ы всех кнопок ответа. */
    private function callbacksOf(ServerResponse $response): array
    {
        $result  = $response->getResult();
        $raw     = is_object($result) ? $result->reply_markup : null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! isset($decoded['inline_keyboard']) || ! is_array($decoded['inline_keyboard'])) {
            return [];
        }
        $callbacks = [];
        foreach ($decoded['inline_keyboard'] as $row) {
            foreach ((array) $row as $button) {
                if (is_array($button) && isset($button['callback_data']) && is_string($button['callback_data'])) {
                    $callbacks[] = $button['callback_data'];
                }
            }
        }

        return $callbacks;
    }

    /**
     * multibase-picker-10 (lead-review Major 1) — голый `hangar` у персонажа БЕЗ
     * баз остаётся хабом ADR-120 (инвентарь + lock-объяснение), а не голым отказом.
     */
    public function testBareHangarWithNoBasesRendersHubNotPlainRefusal(): void
    {
        [$tgId] = $this->seedPlayer(777);

        $response = (new HangarAction($this->cbq($tgId, 'hangar')))->handle();
        $text      = $this->textOf($response);
        $callbacks = $this->callbacksOf($response);

        $this->assertStringContainsString('🤖 *Ангар автоматизации*', $text, 'это хаб, не голый отказ');
        $this->assertStringContainsString('🔒', $text);
        $this->assertStringContainsString('Мастерская робототехники', $text);
        $this->assertStringContainsString('Роботы:', $text);
        $this->assertStringContainsString('Дроны:', $text);
        $this->assertNotSame(BaseScopeResolver::TEXT_NO_BASES, $text, 'не голый отказ resolve()');
        $this->assertContains('AllRobots', $callbacks, 'кнопка на роботов есть — не голый отказ');
        $this->assertContains('craftInsuranceList', $callbacks);
        $this->assertContains('Base', $callbacks, 'без базы суффикс не выдумывается');
    }

    /**
     * multibase-picker-10 — голый `hangar`, 2 активные базы, игрок вне обеих и вне
     * сигнала: хаб с инвентарём и lock-строкой, но НИ ОДНА база не названа местной,
     * уровень Мастерской не показывается (ask 4).
     */
    public function testBareHangarWithTwoBasesOutOfSignalRendersHubNamingNoBase(): void
    {
        [$tgId, $charId] = $this->seedPlayer(999); // вне обеих баз и вне сигнала
        $this->seedTwoBases($charId);
        $this->seedWorkshop($charId, 100, 5); // Мастерская есть, но не на базе, куда попал бы resolve()

        $response = (new HangarAction($this->cbq($tgId, 'hangar')))->handle();
        $text      = $this->textOf($response);
        $callbacks = $this->callbacksOf($response);

        $this->assertStringContainsString('🤖 *Ангар автоматизации*', $text, 'это хаб, не голый отказ');
        $this->assertStringContainsString('🔒', $text);
        $this->assertStringNotContainsString('Первая', $text, 'ни одна база не называется местной');
        $this->assertStringNotContainsString('Вторая', $text, 'ни одна база не называется местной');
        $this->assertStringNotContainsString('уровень 5', $text, 'уровень Мастерской не показывается без базы в охвате');
        $this->assertNotSame(BaseScopeResolver::TEXT_AMBIGUOUS, $text, 'не голый отказ resolve()');
        $this->assertContains('AllRobots', $callbacks);
        $this->assertContains('Base', $callbacks);
    }
}
