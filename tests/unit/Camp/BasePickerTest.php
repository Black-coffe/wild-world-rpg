<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ShowBaseInfoAction;
use App\Controllers\Telegram\Commands\Actions\Camp\DetailedBaseInfoAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Bases\BaseServiceMessageFormatter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * multibase-picker-02 — пикер баз на «🏠 База» + суффикс базы во всех кнопках
 * выбранной базы (`Base_b<id>`, `construction_b<id>`, `hangar_b<id>`, `campDecor_b<id>`,
 * `baseDevelopment_b<id>`, `building_<id>_<name>_b<id>`).
 *
 * Экран «база с постройками» шлёт фото через `Request::encodeFile(base_url(...))` —
 * в тест-стенде оно недоступно и бросает `TelegramException` ДО того, как MediaSender
 * успевает решить media-off/media-on (тот же сетевой предел, что и в
 * `StartRobotGatheringBaseTest`). Сценарии, доходящие до этого экрана, проверяются
 * ловлей исключения (по сообщению — оно называет именно `base_with_its_buildings.jpg`,
 * то есть путь реально дошёл до рендера построек, а не до отказа/пикера) плюс DB-следом
 * (`touchVisit` пишет `last_visited_at` ИМЕННО выбранной базы). Текстовые экраны
 * (пикер, «недоступна», «нет базы») сетевой сети не задевают и проверяются полностью.
 *
 * @internal
 */
final class BasePickerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'bp2_';

    /** @var array<string,string> таблица => колонки. */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, level INT NOT NULL DEFAULT 99, locale VARCHAR(8) NULL',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, map_cell_id INT NOT NULL, claimed_at DATETIME NULL, last_visited_at DATETIME NULL, last_warned_at DATETIME NULL, status VARCHAR(16) NOT NULL, camp_name VARCHAR(64) NULL, camp_flag VARCHAR(16) NULL, camp_hearth VARCHAR(64) NULL, camp_furniture VARCHAR(64) NULL, camp_pet VARCHAR(64) NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, tax INT NULL, amount INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'biomes'               => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, description TEXT NULL, danger_level INT NULL, survival_difficulty INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'tasks'                => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL',
        'character_tasks'      => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    private string $origPrefix = '';
    private int $towerBuildingId = 0;
    private int $biomeId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);
        foreach (self::TABLES as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) DEFAULT CHARSET=utf8mb4");
        }

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }

        $this->db()->table('buildings')->insert(['name_ru' => 'Вышка связи', 'name_en' => 'CommunicationTower']);
        $this->towerBuildingId = (int) $this->db()->insertID();

        $this->db()->table('biomes')->insert(['name' => 'Лес', 'danger_level' => 1, 'survival_difficulty' => 1]);
        $this->biomeId = (int) $this->db()->insertID();
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
        }

        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function addMapCell(int $cellNumber, int $x, int $y): void
    {
        $this->db()->table('map')->insert([
            'id' => $cellNumber, 'cell_number' => $cellNumber,
            'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => $this->biomeId,
        ]);
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(int $standingOnCell): array
    {
        $tgId = random_int(770_000_000, 779_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'cell_number' => $standingOnCell, 'locale' => 'ru', 'level' => 99,
        ]);
        $charId = (int) $this->db()->insertID();

        return [$tgId, $charId];
    }

    /** Активная база; опционально своя Вышка связи заданного уровня. */
    private function addBase(int $charId, int $cellNumber, ?string $campName = null, ?int $towerLevel = null): int
    {
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $cellNumber,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'camp_name' => $campName,
        ]);
        $baseId = (int) $this->db()->insertID();

        if ($towerLevel !== null) {
            $this->db()->table('character_buildings')->insert([
                'character_id' => $charId, 'building_id' => $this->towerBuildingId,
                'map_cell_id' => $cellNumber, 'level' => $towerLevel,
            ]);
        }

        return $baseId;
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

    private function textOf(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result)) {
            return '';
        }
        $text = $result->getText();

        return is_string($text) ? $text : '';
    }

    /** @return list<array{text:string,callback_data:string}> */
    private function buttonsOf(ServerResponse $response): array
    {
        $result  = $response->getResult();
        $raw     = is_object($result) ? $result->reply_markup : null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! isset($decoded['inline_keyboard']) || ! is_array($decoded['inline_keyboard'])) {
            return [];
        }
        $flat = [];
        foreach ($decoded['inline_keyboard'] as $row) {
            foreach ((array) $row as $button) {
                if (is_array($button)) {
                    $flat[] = $button;
                }
            }
        }

        return $flat;
    }

    private function lastVisitedAt(int $baseId): ?string
    {
        $row = $this->db()->table('claimed_cells')->where('id', $baseId)->get()->getRowArray();

        return $row['last_visited_at'] ?? null;
    }

    // ── Пикер: текстовые экраны (без сетевого фото) ──────────────────────────

    public function testTwoBasesUnderSignalShowPickerWithTwoButtons(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);
        $this->addMapCell(300, 15, 15); // игрок между базами — обе в радиусе 100 (дефолт)

        [$tgId, $charId] = $this->seedCharacter(300);
        $id1 = $this->addBase($charId, 100, 'Первая', 1);
        $id2 = $this->addBase($charId, 200, 'Вторая', 1);

        $response = (new ShowBaseInfoAction($this->cbq($tgId, 'Base')))->handle();
        $this->assertTrue($response->isOk());

        $text      = $this->textOf($response);
        $callbacks = array_column($this->buttonsOf($response), 'callback_data');

        $this->assertStringContainsString('Активных баз: *2*', $text);
        $this->assertContains("Base_b{$id1}", $callbacks);
        $this->assertContains("Base_b{$id2}", $callbacks);
        $this->assertContains('TeleportToCamp', $callbacks);
        $this->assertContains('move', $callbacks);
    }

    public function testNoBaseUnderSignalListsAllBasesAsTextWithDistance(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);
        $this->addMapCell(900, 900, 900); // далеко от обеих баз

        [$tgId, $charId] = $this->seedCharacter(900);
        $this->addBase($charId, 100, 'Первая', 1); // Вышка есть, но не дотягивается
        $this->addBase($charId, 200, 'Вторая', null); // Вышки нет вовсе

        $response = (new ShowBaseInfoAction($this->cbq($tgId, 'Base')))->handle();
        $this->assertTrue($response->isOk());

        $text      = $this->textOf($response);
        $callbacks = array_column($this->buttonsOf($response), 'callback_data');

        $this->assertStringContainsString('Первая', $text);
        $this->assertStringContainsString('Вторая', $text);
        $this->assertStringContainsString('ходов', $text, 'расстояние обязано быть в тексте (media-off)');
        $this->assertContains('TeleportToCamp', $callbacks);
        $this->assertContains('move', $callbacks);
        $this->assertEmpty(array_filter($callbacks, static fn (string $c): bool => str_starts_with($c, 'Base_b')), 'ни одна база не под сигналом — кнопок баз быть не должно');
    }

    // ── Честный отказ на _b<id> недоступной базы (тоже текст, без фото) ──────

    public function testCallbackWithForeignBaseIdIsRefused(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);

        [$tgId] = $this->seedCharacter(100);
        [, $otherCharId] = $this->seedCharacter(200);
        $foreignBaseId = $this->addBase($otherCharId, 200, 'Чужая', 1);

        $response = (new ShowBaseInfoAction($this->cbq($tgId, "Base_b{$foreignBaseId}")))->handle();
        $this->assertTrue($response->isOk());
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
    }

    public function testCallbackWithOutOfRangeBaseIdIsRefused(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(999, 999, 999);

        [$tgId, $charId] = $this->seedCharacter(999); // не на базе и вне сигнала
        $baseId = $this->addBase($charId, 100, 'Первая', 1);

        $response = (new ShowBaseInfoAction($this->cbq($tgId, "Base_b{$baseId}")))->handle();
        $this->assertTrue($response->isOk());
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
    }

    // ── Экран выбранной базы (фото недостижимо в тест-стенде — ловим исключение) ──

    public function testStandingOnSecondBaseTouchesItsVisitNotTheOthers(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);

        [$tgId, $charId] = $this->seedCharacter(200); // стоит на «Вторая»
        $id1 = $this->addBase($charId, 100, 'Первая', 1);
        $id2 = $this->addBase($charId, 200, 'Вторая', 1);

        try {
            (new ShowBaseInfoAction($this->cbq($tgId, 'Base')))->handle();
            $this->fail('Ожидалось TelegramException от encodeFile(base_url(...)) — в тест-стенде фото недостижимо.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('base_with_its_buildings.jpg', $e->getMessage(), 'исключение обязано быть именно от рендера построек, не от другой ошибки');
        }

        $this->assertNotNull($this->lastVisitedAt($id2), 'визит на «Вторая» обязан зафиксироваться');
        $this->assertNull($this->lastVisitedAt($id1), '«Первая» не должна быть тронута — игрок физически на «Вторая»');
    }

    public function testExactlyOneBaseUnderSignalOpensDirectlyWithoutPicker(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);
        $this->addMapCell(300, 11, 11); // игрок рядом с базой-1, не на ней

        [$tgId, $charId] = $this->seedCharacter(300);

        $this->addBase($charId, 100, 'Первая', 1); // покрывает клетку 300 (дистанция 1 <= 100)
        $this->addBase($charId, 200, 'Вторая', null); // Вышки нет — никогда не покрыта

        try {
            (new ShowBaseInfoAction($this->cbq($tgId, 'Base')))->handle();
            $this->fail('Ожидалось TelegramException от encodeFile(base_url(...)) — в тест-стенде фото недостижимо.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'base_with_its_buildings.jpg',
                $e->getMessage(),
                'ровно одна база под сигналом обязана открыть экран построек сразу, без пикера (тот отвечал бы sendMessage без исключения)'
            );
        }
    }

    public function testAvailableSuffixOpensScreenUnavailableOneRefuses(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(999, 999, 999);

        [$tgId, $charId] = $this->seedCharacter(100); // физически на базе-1
        $id1 = $this->addBase($charId, 100, 'Первая', 1);
        $id2 = $this->addBase($charId, 999, 'Вторая', null); // недоступна (не на ней, Вышки нет)

        // Недоступная — честный отказ, без сети.
        $refusal = (new ShowBaseInfoAction($this->cbq($tgId, "Base_b{$id2}")))->handle();
        $this->assertTrue($refusal->isOk());
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($refusal));

        // Доступная (физически на ней) — обязана дойти до рендера построек.
        try {
            (new ShowBaseInfoAction($this->cbq($tgId, "Base_b{$id1}")))->handle();
            $this->fail('Ожидалось TelegramException от encodeFile(base_url(...)).');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('base_with_its_buildings.jpg', $e->getMessage());
        }
    }

    // ── DetailedBaseInfoAction (`construction_b<id>`) — тот же отказ, тот же рендер ──

    public function testConstructionScreenWithForeignSuffixIsRefused(): void
    {
        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);

        [$tgId] = $this->seedCharacter(100);
        [, $otherCharId] = $this->seedCharacter(200);
        $foreignBaseId = $this->addBase($otherCharId, 200, 'Чужая', 1);

        $response = (new DetailedBaseInfoAction($this->cbq($tgId, "construction_b{$foreignBaseId}")))->handle();
        $this->assertTrue($response->isOk());
        $this->assertSame(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
    }

    public function testConstructionScreenWithAvailableSuffixReachesBuildingsRender(): void
    {
        $this->addMapCell(100, 10, 10);
        [$tgId, $charId] = $this->seedCharacter(100);
        $id1 = $this->addBase($charId, 100, 'Первая', 1);

        try {
            (new DetailedBaseInfoAction($this->cbq($tgId, "construction_b{$id1}")))->handle();
            $this->fail('Ожидалось TelegramException от encodeFile(base_url(...)).');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('base_with_its_buildings.jpg', $e->getMessage());
        }
    }

    // ── Формат: суффикс на каждой кнопке экрана выбранной базы, ≤64 байта ────

    public function testBaseBuildingsButtonsCarrySuffixWithinByteLimit(): void
    {
        $bigBaseId = 9_999_999_999; // 10 знаков (контракт: «id базы в 10 знаков»)
        $payload   = (new BaseServiceMessageFormatter())->baseBuildings(
            10, 10, 'Лес', 3, 30, "🚰 Скважина\n", null, null, null, true, null, $bigBaseId
        );

        $keyboard  = json_decode((string) $payload['reply_markup'], true);
        $callbacks = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'];
            }
        }

        $this->assertContains("construction_b{$bigBaseId}", $callbacks);
        $this->assertContains("hangar_b{$bigBaseId}", $callbacks);
        $this->assertContains("campDecor_b{$bigBaseId}", $callbacks);
        foreach (['construction', 'hangar', 'campDecor'] as $prefix) {
            $cb = "{$prefix}_b{$bigBaseId}";
            $this->assertLessThanOrEqual(64, strlen($cb), "{$cb} обязан влезать в лимит Telegram");
        }
    }

    public function testLongestBuildingNameWithTenDigitBaseIdFitsCallbackLimit(): void
    {
        // Самое длинное английское имя постройки в Config\Buildings — 'TeleportationCenter' (19 симв.).
        $cb = BaseCallbackSuffix::append('building_999_TeleportationCenter', 9_999_999_999);
        $this->assertLessThanOrEqual(64, strlen($cb));
        $this->assertSame('building_999_TeleportationCenter_b9999999999', $cb);

        $devCb = BaseCallbackSuffix::append('baseDevelopment', 9_999_999_999);
        $this->assertLessThanOrEqual(64, strlen($devCb));
    }

    // ── media-off: пикер полон без картинки ───────────────────────────────────

    public function testBasePickerTextCarriesAllInfoWithoutPhoto(): void
    {
        // Production зовёт basePicker() только когда покрытых баз НЕ ровно одна
        // (иначе showBasePicker() открывает её напрямую) — 2 покрытых здесь реалистичны.
        $bases = [
            ['base_id' => 1, 'cell' => 100, 'name' => 'Первая', 'x' => 10, 'y' => 10, 'towerLevel' => 1, 'distance' => 3, 'maxCoverage' => 100, 'isCovered' => true],
            ['base_id' => 2, 'cell' => 200, 'name' => 'Вторая', 'x' => 20, 'y' => 20, 'towerLevel' => 1, 'distance' => 5, 'maxCoverage' => 100, 'isCovered' => true],
            ['base_id' => 3, 'cell' => 300, 'name' => 'Третья', 'x' => 30, 'y' => 30, 'towerLevel' => 0, 'distance' => 400, 'maxCoverage' => 0, 'isCovered' => false],
        ];
        $payload = (new BaseServiceMessageFormatter())->basePicker($bases);

        $this->assertStringContainsString('Первая', $payload['text']);
        $this->assertStringContainsString('X=10, Y=10', $payload['text']);
        $this->assertStringContainsString('Вторая', $payload['text']);
        $this->assertStringContainsString('Третья', $payload['text']);
        $this->assertStringContainsString('400 ходов', $payload['text']);
        $this->assertSame('Markdown', $payload['parse_mode']);

        $callbacks = [];
        $keyboard  = json_decode((string) $payload['reply_markup'], true);
        foreach ($keyboard['inline_keyboard'] as $row) {
            $this->assertGreaterThan(1, count($row), 'правило «ноль одиночек в ряду»');
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'];
            }
        }
        $this->assertContains('Base_b1', $callbacks);
        $this->assertContains('Base_b2', $callbacks);
        $this->assertNotContains('Base_b3', $callbacks, 'непокрытая база кнопкой не становится');
    }
}
