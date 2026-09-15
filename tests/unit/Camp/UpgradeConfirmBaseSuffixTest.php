<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Player\BuildingUpgrade\BuildingUpgradeMessageFormatter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * multibase-picker-08 — подтверждение апгрейда несёт суффикс `_b<baseId>`, если
 * запрос апгрейда пришёл с ним (`BuildingUpgradeMessageFormatter::askPrompt()`),
 * и клик по такой кнопке доходит до `BuildingUpgradeValidator::validate(..., $baseId)`
 * (история 03) — меняет строку `character_buildings` именно ЭТОЙ базы.
 *
 * Схема — свой приватный префикс `ucbs_`, тест строит её сам
 * (`feedback_test_schema_must_come_from_migration`), тот же DDL-паттерн, что
 * `BuildingCardBaseChoiceTest` (multibase-picker-03) — реальные модели/action через
 * `BaseConnection::setPrefix()` без единой правки app/.
 *
 * @internal
 */
final class UpgradeConfirmBaseSuffixTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'ucbs_';

    /** @var list<string> */
    private const TABLES = [
        'telegram_users', 'characters', 'buildings', 'character_buildings',
        'claimed_cells', 'map', 'game_settings',
    ];

    private string $origPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        // Нужен, чтобы App\Services\Telegram\Request коротил на фейковый ServerResponse
        // вместо реального HTTP (урок feedback_taskhandler_telegram_init_in_tests).
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);
        $this->resetBuildingModelStaticCache();

        $ddl = [
            'telegram_users' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
            'characters' => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, '
                . 'name VARCHAR(64) NULL, cell_number INT NULL, locale VARCHAR(8) NULL, '
                . 'level INT NULL DEFAULT 1, gold DOUBLE NULL DEFAULT 1000, '
                . 'created_at DATETIME NULL, updated_at DATETIME NULL',
            'buildings' => "id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, "
                . "name_en VARCHAR(255) NULL, "
                . "building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL, "
                . 'hp INT NULL, usage_count INT NULL, description VARCHAR(255) NULL',
            'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, '
                . 'building_id INT NULL, map_cell_id INT NULL, amount INT NULL DEFAULT 1, hp INT NULL, '
                . 'level INT NULL DEFAULT 1, built_at DATETIME NULL, building_type VARCHAR(32) NULL, '
                . 'tax INT NULL, `usage` VARCHAR(16) NULL, disappearance_date DATETIME NULL, '
                . 'usage_count INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
            'claimed_cells' => "id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, "
                . "map_cell_id INT NULL, claimed_at DATETIME NULL, status VARCHAR(16) NULL DEFAULT 'active'",
            'map' => 'id INT PRIMARY KEY, cell_number INT NOT NULL, coordinate_x INT NOT NULL, coordinate_y INT NOT NULL',
            'game_settings' => 'id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(128) NOT NULL, '
                . 'value_type VARCHAR(16) NOT NULL, value_int INT NULL, value_float DOUBLE NULL, '
                . 'value_bool TINYINT NULL, value_string VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        ];
        foreach ($ddl as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) ENGINE=InnoDB");
        }

        $this->cleanCache();
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
            $this->cleanCache();
        }

        parent::tearDown();
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function cleanCache(): void
    {
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    private function resetBuildingModelStaticCache(): void
    {
        $prop = new \ReflectionProperty(\App\Models\BuildingModel::class, 'byNameEnCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ---- seeding ----

    private function seedMapLine(int ...$cells): void
    {
        foreach ($cells as $cell) {
            $this->db()->table('map')->insert(['id' => $cell, 'cell_number' => $cell, 'coordinate_x' => $cell, 'coordinate_y' => 0]);
        }
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(int $cellNumber): array
    {
        $tgId = random_int(720_000_000, 729_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

        $this->db()->table('characters')->insert([
            // gold покрывает реальный `Config\BuildingUpgrades::$requirements[2]['gold']`
            // (50000) — confirmUpgrade() зовёт настоящий конфиг, не двойник.
            'telegram_user_id' => $tgUid, 'cell_number' => $cellNumber, 'locale' => 'ru', 'level' => 10, 'gold' => 100000,
        ]);
        $charId = (int) $this->db()->insertID();

        return [$tgId, $charId];
    }

    private function seedBase(int $charId, int $cell, string $status = 'active'): int
    {
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $cell,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => $status,
        ]);

        return (int) $this->db()->insertID();
    }

    private function seedBuilding(string $nameRu, string $nameEn): int
    {
        $this->db()->table('buildings')->insert([
            'name_ru' => $nameRu, 'name_en' => $nameEn, 'building_type' => 'resource', 'hp' => 100,
        ]);

        return (int) $this->db()->insertID();
    }

    private function seedCharacterBuilding(int $charId, int $buildingId, int $cell, int $level): int
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell,
            'hp' => 100, 'level' => $level, 'built_at' => date('Y-m-d H:i:s'),
            'building_type' => 'resource', 'tax' => 10, 'usage' => 'personal',
        ]);

        return (int) $this->db()->insertID();
    }

    private function rowLevel(int $rowId): int
    {
        $row = $this->db()->table('character_buildings')->where('id', $rowId)->get()->getRowArray();

        return (int) $row['level'];
    }

    /** Новая CallbackQuery на каждый вызов handle(). */
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

    // ---- AC 1: askPrompt() с базой строит confirm-кнопку с суффиксом, ≤64 байт ----

    public function testAskPromptWithBaseAppendsSuffixWithinByteLimit(): void
    {
        $formatter = new BuildingUpgradeMessageFormatter();
        $character = ['level' => 10, 'gold' => 1000];

        $payload = $formatter->askPrompt(4, 'Мастерская', 1, 2, 1, 0, [], $character, 345);

        $keyboard = json_decode((string) $payload['reply_markup'], true);
        $confirmCallback = (string) $keyboard['inline_keyboard'][0][0]['callback_data'];

        $this->assertSame('confirm_upgrade_building_4' . BaseCallbackSuffix::append('', 345), $confirmCallback);
        $this->assertSame('confirm_upgrade_building_4_b345', $confirmCallback);
        $this->assertLessThanOrEqual(64, strlen($confirmCallback));
    }

    // ---- AC 1: без базы — байт в байт как раньше (без суффикса) ----

    public function testAskPromptWithoutBaseIsByteIdenticalToLegacy(): void
    {
        $formatter = new BuildingUpgradeMessageFormatter();
        $character = ['level' => 10, 'gold' => 1000];

        $withBaseNull    = $formatter->askPrompt(4, 'Мастерская', 1, 2, 1, 0, [], $character, null);
        $withoutBaseParam = $formatter->askPrompt(4, 'Мастерская', 1, 2, 1, 0, [], $character);

        $this->assertSame($withoutBaseParam, $withBaseNull);

        $keyboard = json_decode((string) $withBaseNull['reply_markup'], true);
        $this->assertSame(
            'confirm_upgrade_building_4',
            $keyboard['inline_keyboard'][0][0]['callback_data']
        );
    }

    // ---- AC 2: клик по подтверждению с суффиксом меняет level только своей базы ----

    public function testConfirmClickWithBaseSuffixUpgradesOnlyThatBasesRow(): void
    {
        $this->seedMapLine(0, 1000);
        // name_en пустой намеренно: `Config\Endgame::$buildingFactionMap` знает
        // 'HandPump' и завёл бы недостающую в этой изолированной схеме таблицу
        // `faction_endgame_scores` — вне ## Files этой истории.
        $handPumpId = $this->seedBuilding('Ручная скважина', '');

        [$tgId, $charId] = $this->seedCharacter(1000); // игрок стоит на базе 2
        $this->seedBase($charId, 0);
        $base2 = $this->seedBase($charId, 1000);
        // Оба уровня — 1 (currentLevel=1 → nextLevel=2), чтобы попасть в бесплатный
        // (по ресурсам) шаг реального `Config\BuildingUpgrades::$requirements[2]`.
        $row1  = $this->seedCharacterBuilding($charId, $handPumpId, 0, 1);
        $row2  = $this->seedCharacterBuilding($charId, $handPumpId, 1000, 1);

        $confirmData = 'confirm_upgrade_building_' . $handPumpId . BaseCallbackSuffix::append('', $base2);
        $response = (new UpgradeBuildingAction($this->cbq($tgId, $confirmData)))->confirmUpgrade();

        $this->assertSame(2, $this->rowLevel($row2), 'уровень поднялся у строки базы, пришедшей в суффиксе');
        $this->assertSame(1, $this->rowLevel($row1), 'строка другой базы не тронута');
        $this->assertStringNotContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
    }

    // ---- AC 2: чужая/неактивная/недоступная база в суффиксе — честный отказ, без изменений ----

    public function testConfirmClickWithForeignBaseSuffixIsUnavailableAndDoesNotApply(): void
    {
        $this->seedMapLine(0, 1000, 2000);
        $handPumpId = $this->seedBuilding('Ручная скважина', '');

        [$tgId, $charId] = $this->seedCharacter(1000); // игрок стоит на своей базе
        $this->seedBase($charId, 1000);
        $row = $this->seedCharacterBuilding($charId, $handPumpId, 1000, 1);

        // Чужая база — принадлежит другому персонажу.
        $foreign = $this->seedBase($charId + 999, 2000);

        $confirmData = 'confirm_upgrade_building_' . $handPumpId . BaseCallbackSuffix::append('', $foreign);
        $response = (new UpgradeBuildingAction($this->cbq($tgId, $confirmData)))->confirmUpgrade();

        $this->assertSame(1, $this->rowLevel($row), 'уровень не изменился при недоступной базе из суффикса');
        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));

        // Неактивная (abandoned) своя база.
        $abandoned = $this->seedBase($charId, 500, 'abandoned');
        $confirmDataAbandoned = 'confirm_upgrade_building_' . $handPumpId . BaseCallbackSuffix::append('', $abandoned);
        $responseAbandoned = (new UpgradeBuildingAction($this->cbq($tgId, $confirmDataAbandoned)))->confirmUpgrade();

        $this->assertSame(1, $this->rowLevel($row), 'уровень не изменился при неактивной базе из суффикса');
        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($responseAbandoned));
    }
}
