<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\BaseDevelopmentAction;
use App\Services\Bases\BaseScopeResolver;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * story multibase-picker-06 — твин `BaseDevelopmentAction.php:70-75` (recon.md «Твины»):
 * запрос группировал `MAX(cb.level)` по `character_id` без `map_cell_id`, так что «Развитие
 * базы» на базе-2 без своей прокачки показывало уровень с базы-1. Суффикс `_b<id>` в
 * `callback_data` резолвится через `BaseScopeResolver::resolveForBase()` и фильтрует запрос
 * по клетке ВЫБРАННОЙ базы; без суффикса — прежнее правило `resolve()`.
 *
 * Тест бьёт по поведению `handle()` (реальный текст ответа), не по наличию `map_cell_id` в
 * исходнике (`feedback_source_scan_tests_are_not_coverage`). Своя приватно-префиксная схема
 * (`feedback_test_schema_must_come_from_migration`, `feedback_local_green_on_empty_test_db_proves_nothing`),
 * тот же DDL-паттерн, что `BuildingCardBaseScopeTest`.
 *
 * @internal
 */
final class BaseDevelopmentBaseScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'bdbs_';

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

        $this->createOwnTable('telegram_users', '
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT NULL
            )
        ', ['id', 'telegram_id']);
        $this->createOwnTable('characters', '
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_user_id INT NULL,
                name VARCHAR(64) NULL,
                cell_number INT NULL,
                locale VARCHAR(8) NULL,
                level INT NULL DEFAULT 1,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ', ['id', 'telegram_user_id', 'cell_number', 'locale']);
        $this->createOwnTable('buildings', "
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_ru VARCHAR(255) NULL,
                name_en VARCHAR(255) NULL,
                building_type ENUM('military','residential','farming','resource','engineering','defensive') NULL,
                hp INT NULL,
                usage_count INT NULL,
                description VARCHAR(255) NULL
            )
        ", ['id', 'name_ru', 'name_en', 'building_type']);
        $this->createOwnTable('character_buildings', "
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                building_id INT NULL,
                map_cell_id INT NULL,
                amount INT NULL DEFAULT 1,
                hp INT NULL,
                level INT NULL DEFAULT 1,
                built_at DATETIME NULL,
                building_type VARCHAR(32) NULL,
                tax INT NULL,
                `usage` VARCHAR(16) NULL,
                disappearance_date DATETIME NULL,
                usage_count INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ", ['id', 'character_id', 'building_id', 'map_cell_id', 'level']);
        $this->createOwnTable('claimed_cells', '
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                map_cell_id INT NULL,
                claimed_at DATETIME NULL,
                status VARCHAR(16) NULL DEFAULT "active"
            )
        ', ['id', 'character_id', 'map_cell_id', 'status']);

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        $this->db()->setPrefix($this->origPrefix);

        parent::tearDown();
    }

    /** @var list<string> */
    private const TABLES = ['telegram_users', 'characters', 'buildings', 'character_buildings', 'claimed_cells'];

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    /** @param list<string> $requiredColumns */
    private function createOwnTable(string $table, string $ddl, array $requiredColumns): void
    {
        $prefixed = self::PREFIX . $table;
        $this->db()->query("DROP TABLE IF EXISTS {$prefixed}");
        $this->db()->query(str_replace('__TABLE__', $prefixed, $ddl));

        $actual  = $this->db()->getFieldNames($prefixed);
        $missing = array_diff($requiredColumns, $actual);
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'BaseDevelopmentBaseScopeTest: таблица %s существует, но в ней нет колонки %s.',
                $prefixed,
                implode(', ', $missing)
            ));
        }
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(int $cellNumber): array
    {
        $tgId = random_int(720_000_000, 729_999_999);
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();

        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'cell_number' => $cellNumber, 'locale' => 'ru',
        ]);
        $charId = (int) $this->db()->insertID();

        return [$tgId, $charId];
    }

    private function setCharacterCell(int $charId, int $cellNumber): void
    {
        $this->db()->table('characters')->where('id', $charId)->update(['cell_number' => $cellNumber]);
    }

    /** @return int claimed_cells.id */
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

    private function seedCharacterBuilding(int $charId, int $buildingId, int $cell, int $level): void
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell,
            'hp' => 100, 'level' => $level, 'built_at' => date('Y-m-d H:i:s'),
            'building_type' => 'resource', 'tax' => 10, 'usage' => 'personal',
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

    private function textOf(ServerResponse $response): string
    {
        $result = $response->getResult();
        if (! is_object($result)) {
            return '';
        }
        $text = $result->getText();

        return is_string($text) ? $text : '';
    }

    public function testSuffixSelectsLevelOfChosenBaseNotTheOtherBase(): void
    {
        $buildingId = $this->seedBuilding('Ручная скважина', 'HandPump');

        [$tgId, $charId] = $this->seedCharacter(100);
        $baseAId = $this->seedBase($charId, 100);
        $baseBId = $this->seedBase($charId, 200);
        $this->seedCharacterBuilding($charId, $buildingId, 100, 7); // база A — L7
        $this->seedCharacterBuilding($charId, $buildingId, 200, 1); // база B — L1

        // Игрок физически на базе A; суффикс `_b<baseAId>` (on_base) → уровень 7.
        $responseA = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment_b' . $baseAId)))->handle();
        $this->assertStringContainsString('7/10', $this->textOf($responseA));
        $this->assertStringNotContainsString('1/10', $this->textOf($responseA));

        // Переставляем игрока на базу B и бьём суффиксом `_b<baseBId>` → уровень 1, не 7.
        $this->setCharacterCell($charId, 200);
        $responseB = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment_b' . $baseBId)))->handle();
        $this->assertStringContainsString('1/10', $this->textOf($responseB));
        $this->assertStringNotContainsString('7/10', $this->textOf($responseB));
    }

    public function testForeignBaseSuffixGetsUnavailableText(): void
    {
        $buildingId          = $this->seedBuilding('Ручная скважина', 'HandPump');
        [$tgId, $charId]     = $this->seedCharacter(100);
        $this->seedBase($charId, 100);
        $this->seedCharacterBuilding($charId, $buildingId, 100, 5);

        [, $otherCharId] = $this->seedCharacter(300);
        $foreignBaseId    = $this->seedBase($otherCharId, 300);

        $response = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment_b' . $foreignBaseId)))->handle();
        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
        $this->assertStringNotContainsString('5/10', $this->textOf($response));
    }

    public function testInactiveBaseSuffixGetsUnavailableText(): void
    {
        $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
        [$tgId, $charId] = $this->seedCharacter(100);
        $this->seedBase($charId, 100);
        $this->seedCharacterBuilding($charId, $buildingId, 100, 5);
        $abandonedBaseId = $this->seedBase($charId, 400, 'abandoned');

        $response = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment_b' . $abandonedBaseId)))->handle();
        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
    }

    public function testNoSuffixSingleBaseUnaffected(): void
    {
        $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
        [$tgId, $charId] = $this->seedCharacter(100);
        $this->seedBase($charId, 100);
        $this->seedCharacterBuilding($charId, $buildingId, 100, 4);

        $response = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment')))->handle();
        $this->assertStringContainsString('4/10', $this->textOf($response));
    }

    public function testNoSuffixAmbiguousGetsLegacyAmbiguousText(): void
    {
        $buildingId      = $this->seedBuilding('Ручная скважина', 'HandPump');
        [$tgId, $charId] = $this->seedCharacter(999); // не стоит ни на одной из баз
        $this->seedBase($charId, 100);
        $this->seedBase($charId, 200);
        $this->seedCharacterBuilding($charId, $buildingId, 100, 7);
        $this->seedCharacterBuilding($charId, $buildingId, 200, 1);

        $response = (new BaseDevelopmentAction($this->cbq($tgId, 'baseDevelopment')))->handle();
        $this->assertStringContainsString(BaseScopeResolver::TEXT_AMBIGUOUS, $this->textOf($response));
    }
}
