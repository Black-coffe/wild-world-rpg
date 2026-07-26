<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Buildings\BuildingGateService;
use App\Services\Player\Progression\LevelUnlockService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-155 — сторож дрейфа «какой уровень открывает постройку».
 *
 * Тест намеренно сеет в БД **стухшие** значения `buildings.min_character_level` (те самые, что
 * стояли на проде до правки: Спортзал 0, Теплица 8) и требует, чтобы строка «что откроется»
 * их проигнорировала и посчитала постройки по `Config\Buildings.level_required` — источнику,
 * который реально гейтит стройку.
 *
 * Без этого сторожа регресс незаметен: unit-тест `LevelUnlockServiceTest` подменяет `countsFor()`
 * целиком, а на проде расхождение проявляется молча — Спортзал (реально L5) не попадал ни в одну
 * строку «на N-м уровне», потому что колонка говорила 0.
 *
 * @internal
 */
final class LevelUnlockBuildingGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        LevelUnlockService::resetCache();
        BuildingGateService::resetCache();

        $db = Database::connect('tests');
        foreach (['resources', 'crafted_items', 'weapons', 'outfits', 'buildings', 'quests', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE resources (id INT AUTO_INCREMENT PRIMARY KEY, level_required INT NULL)');
        $db->query('CREATE TABLE crafted_items (id INT AUTO_INCREMENT PRIMARY KEY, required_level INT NULL)');
        $db->query('CREATE TABLE weapons (id INT AUTO_INCREMENT PRIMARY KEY, required_level INT NULL)');
        $db->query('CREATE TABLE outfits (id INT AUTO_INCREMENT PRIMARY KEY, required_level INT NULL)');
        $db->query('CREATE TABLE quests (id INT AUTO_INCREMENT PRIMARY KEY, min_level INT NULL)');
        $db->query('CREATE TABLE buildings (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(64), min_character_level INT NULL)');
        $db->query('CREATE TABLE game_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), category VARCHAR(64) NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_float DECIMAL(15,5) NULL, value_bool TINYINT NULL, value_string TEXT NULL, hard_min VARCHAR(32) NULL, hard_max VARCHAR(32) NULL)');

        // Стухшие значения с прода до ADR-155.
        $db->table('buildings')->insertBatch([
            ['name_en' => 'Gym', 'min_character_level' => 0],
            ['name_en' => 'Greenhouse', 'min_character_level' => 8],
            ['name_en' => 'Workshop', 'min_character_level' => 4],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (['resources', 'crafted_items', 'weapons', 'outfits', 'buildings', 'quests', 'game_settings'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        LevelUnlockService::resetCache();
        BuildingGateService::resetCache();
        parent::tearDown();
    }

    /**
     * Спортзал числится в БД как «уровень 0» (не попал бы ни в одну строку), а реально
     * открывается на 5-м. Строка обязана посчитать ровно столько построек, сколько говорит
     * конфиг, — и Спортзал среди них.
     */
    public function testGymCountedAtItsRealGateNotTheStaleColumn(): void
    {
        $gates = new BuildingGateService();
        $this->assertContains('Спортзал', $gates->unlockedAt(5), 'предпосылка теста: конфиг ставит Спортзал на L5');

        $line = (string) (new LevelUnlockService())->summaryFor(5);

        $this->assertStringContainsString($gates->countUnlockedAt(5) . ' постройки', $line);
    }

    /**
     * Теплица помечена в БД восьмым уровнем, а строится с первого. На 8-м открывается только
     * Деревянная стена — значит счётчик обязан быть ровно конфиговым, без «лишней» Теплицы.
     */
    public function testGreenhouseNotPromisedAtStaleLevel(): void
    {
        $gates = new BuildingGateService();
        $this->assertNotContains('Теплица', $gates->unlockedAt(8));

        $line = (string) (new LevelUnlockService())->summaryFor(8);

        $this->assertSame(1, $gates->countUnlockedAt(8), 'предпосылка: на L8 в конфиге одна постройка');
        $this->assertStringContainsString('1 постройка', $line);
    }

    /** Мастерская помечена четвёртым, строится с первого — на 4-м построек нет вовсе. */
    public function testWorkshopNotPromisedAtStaleLevel(): void
    {
        $gates = new BuildingGateService();
        $this->assertSame(0, $gates->countUnlockedAt(4));

        $line = (string) (new LevelUnlockService())->summaryFor(4);

        $this->assertStringNotContainsString('постройк', $line);
    }
}
