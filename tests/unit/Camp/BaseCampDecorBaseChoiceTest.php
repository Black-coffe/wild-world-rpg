<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Decor\BaseCampDecorAction;
use App\Services\Bases\BaseScopeResolver;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Telegram;

/**
 * story multibase-picker-06 — твин `BaseCampDecorService.php:124-134 resolveCell()`
 * (recon.md «Твины»): раньше `BaseCampDecorAction` всегда правило базу, на которой персонаж
 * стоит физически СЕЙЧАС, — суффикс `_b<id>` в `callback_data` не читался нигде. Теперь
 * action резолвит базу через `BaseScopeResolver::resolveForBase()` и несёт суффикс дальше по
 * всей цепочке экранов (палитры, «Назад», сохранение пресета), иначе выбор терялся бы на
 * первом клике вглубь декора.
 *
 * Тест бьёт по поведению `handle()`. Своя приватно-префиксная схема, тот же DDL-паттерн, что
 * `BuildingCardBaseScopeTest`/`BaseDevelopmentBaseScopeTest`.
 *
 * @internal
 */
final class BaseCampDecorBaseChoiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'bcdc_';

    private string $origPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
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
        $this->createOwnTable('claimed_cells', '
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NULL,
                map_cell_id INT NULL,
                claimed_at DATETIME NULL,
                status VARCHAR(16) NULL DEFAULT "active",
                camp_name VARCHAR(64) NULL,
                camp_flag VARCHAR(16) NULL,
                camp_hearth VARCHAR(64) NULL,
                camp_furniture VARCHAR(64) NULL,
                camp_pet VARCHAR(64) NULL
            )
        ', ['id', 'character_id', 'map_cell_id', 'status', 'camp_name']);
        $this->createOwnTable('game_settings', "
            CREATE TABLE __TABLE__ (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(128) NULL,
                category VARCHAR(64) NULL,
                value_type VARCHAR(16) NULL,
                value_int INT NULL,
                value_float FLOAT NULL,
                value_bool TINYINT NULL,
                value_string VARCHAR(255) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
        ", ['id', 'setting_key', 'value_type', 'value_bool']);

        // Killswitch housing.decoration.enabled — декор должен быть включён для этих тестов.
        $this->db()->table('game_settings')->insert([
            'setting_key' => 'housing.decoration.enabled', 'category' => 'experimental',
            'value_type'  => 'bool', 'value_bool' => 1,
        ]);

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

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }

        parent::tearDown();
    }

    /** @var list<string> */
    private const TABLES = ['telegram_users', 'characters', 'claimed_cells', 'game_settings'];

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
                'BaseCampDecorBaseChoiceTest: таблица %s существует, но в ней нет колонки %s.',
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

    private function campNameOf(int $baseId): ?string
    {
        $row = $this->db()->table('claimed_cells')->where('id', $baseId)->get()->getRowArray();

        return is_array($row) ? ($row['camp_name'] ?? null) : null;
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

    public function testSuffixEditsChosenBaseOnlyOtherBaseUntouched(): void
    {
        [$tgId, $charId] = $this->seedCharacter(100);
        $baseAId = $this->seedBase($charId, 100);
        $baseBId = $this->seedBase($charId, 200);

        // Игрок стоит на базе B (200) и меняет имя лагеря базы B через суффикс `_b<baseBId>`.
        $this->setCharacterCell($charId, 200);
        (new BaseCampDecorAction($this->cbq($tgId, 'campSetName_0_b' . $baseBId)))->handle();

        $this->assertSame('Форт Надежды', $this->campNameOf($baseBId));
        $this->assertNull($this->campNameOf($baseAId));

        // Возвращаемся на базу A (100) и меняем ЕЁ имя другим индексом через `_b<baseAId>`.
        $this->setCharacterCell($charId, 100);
        (new BaseCampDecorAction($this->cbq($tgId, 'campSetName_1_b' . $baseAId)))->handle();

        $this->assertSame('Последний Рубеж', $this->campNameOf($baseAId));
        // База B по-прежнему несёт своё собственное имя — правка базы A её не задела.
        $this->assertSame('Форт Надежды', $this->campNameOf($baseBId));
    }

    public function testForeignBaseSuffixRefusesWithoutWriting(): void
    {
        [$tgId, $charId] = $this->seedCharacter(100);
        $baseAId          = $this->seedBase($charId, 100);
        [, $otherCharId]  = $this->seedCharacter(300);
        $foreignBaseId    = $this->seedBase($otherCharId, 300);

        $response = (new BaseCampDecorAction($this->cbq($tgId, 'campSetName_0_b' . $foreignBaseId)))->handle();

        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
        $this->assertNull($this->campNameOf($baseAId));
        $this->assertNull($this->campNameOf($foreignBaseId));
    }

    public function testInactiveBaseSuffixRefusesWithoutWriting(): void
    {
        [$tgId, $charId] = $this->seedCharacter(100);
        $this->seedBase($charId, 100);
        $abandonedBaseId = $this->seedBase($charId, 400, 'abandoned');

        $response = (new BaseCampDecorAction($this->cbq($tgId, 'campSetName_0_b' . $abandonedBaseId)))->handle();

        $this->assertStringContainsString(BaseScopeResolver::TEXT_UNAVAILABLE, $this->textOf($response));
        $this->assertNull($this->campNameOf($abandonedBaseId));
    }

    public function testNoSuffixSingleBaseUnaffected(): void
    {
        [$tgId, $charId] = $this->seedCharacter(100);
        $baseId           = $this->seedBase($charId, 100);

        (new BaseCampDecorAction($this->cbq($tgId, 'campSetName_2')))->handle();

        $this->assertSame('Серый Бастион', $this->campNameOf($baseId));
    }
}
