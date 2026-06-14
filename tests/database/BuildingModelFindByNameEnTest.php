<?php

namespace Tests\Database;

use App\Models\BuildingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * E28 — BuildingModel::findByNameEn: резолв строки здания по СТАБИЛЬНОМУ `name_en`
 * (замена хрупкого хардкод-id в *Handler'ах и активаторах роботов, NAVIGATION_MAP #25).
 *
 * Проверяем: верная строка по name_en, null для неизвестного, кэш на процесс,
 * и регресс-смысл фикса — «Арсенал» = id 11 (а не легаси-хардкод 15).
 *
 * @internal
 */
final class BuildingModelFindByNameEnTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('CREATE TABLE buildings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_en VARCHAR(64) NULL,
            name_ru VARCHAR(64) NULL
        )');
        $db->query("INSERT INTO buildings (id, name_en, name_ru) VALUES
            (4, 'Workshop', 'Мастерская'),
            (9, 'RoboticsWorkshop', 'Мастерская робототехники'),
            (11, 'Arsenal', 'Арсенал'),
            (15, 'WatchTower', 'Дозорная вышка')");
        BuildingModel::clearNameEnCache();
    }

    protected function tearDown(): void
    {
        Database::connect('tests')->query('DROP TABLE IF EXISTS buildings');
        BuildingModel::clearNameEnCache();
        parent::tearDown();
    }

    private function model(): BuildingModel
    {
        return new BuildingModel(Database::connect('tests'));
    }

    public function testResolvesRowByNameEn(): void
    {
        $row = $this->model()->findByNameEn('RoboticsWorkshop');
        $this->assertIsArray($row);
        $this->assertSame(9, (int) $row['id']);
        $this->assertSame('Мастерская робототехники', $row['name_ru']);
    }

    public function testArsenalRealIdIsElevenNotLegacyFifteen(): void
    {
        // Смысл фикса: «Арсенал» = 11; легаси-хардкод 15 указывал бы на «Дозорную вышку».
        $arsenal = $this->model()->findByNameEn('Arsenal');
        $this->assertIsArray($arsenal);
        $this->assertSame(11, (int) $arsenal['id']);

        $watchTower = $this->model()->findByNameEn('WatchTower');
        $this->assertIsArray($watchTower);
        $this->assertSame(15, (int) $watchTower['id']);
    }

    public function testUnknownReturnsNull(): void
    {
        $this->assertNull($this->model()->findByNameEn('NopeBuilding'));
    }

    public function testResultIsCachedForProcess(): void
    {
        $m     = $this->model();
        $first = $m->findByNameEn('Workshop');
        $this->assertIsArray($first);

        // Удаляем строку из БД — кэш на процесс обязан вернуть прежний результат.
        Database::connect('tests')->query("DELETE FROM buildings WHERE name_en = 'Workshop'");
        $this->assertSame($first, $m->findByNameEn('Workshop'), 'findByNameEn должен кэшировать результат на процесс.');

        // После явного сброса кэша — уже null (строки нет).
        BuildingModel::clearNameEnCache();
        $this->assertNull($m->findByNameEn('Workshop'));
    }
}
