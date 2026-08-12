<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Bases\BaseCheckService;
use App\Services\Player\PlayerStateService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * Резолв «я на своей базе» в мульти-базовом мире (ADR-095/122).
 *
 * Инцидент 2026-08-12 (найден на ревью дев-блога №10): `PlayerStateService::isCharacterOnBase()`
 * и `BaseCheckService::checkBaseStatus()` брали ПЕРВУЮ строку `claimed_cells` игрока и сравнивали
 * её клетку с текущей. Владелец двух лагерей, стоящий на ВТОРОМ, получал «не на базе» — а этот
 * резолв кормит состояние игрока для мировых событий ({@see \App\Services\Events\EventDispatcher}:
 * `base_idle` = ноль потерь). То есть обещание «на базе не потеряешь» для него было бы ложью —
 * ровно тот класс расхождения, что чинился 09.08 в самой защите базы.
 *
 * Гейт проверен обратной подменой: со старой реализацией (`->where('status','active')->first()`
 * + сравнение по её клетке) `testSecondBaseCountsAsHome` краснеет.
 *
 * @internal
 */
final class OnBaseResolutionMultiBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const CHAR_ID = 900_001;
    private const CELL_A  = 500_101; // первая база (создана раньше)
    private const CELL_B  = 500_202; // вторая база
    private const CELL_C  = 500_303; // чистое поле

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS claimed_cells');
        $db->query('
            CREATE TABLE claimed_cells (
                id INT AUTO_INCREMENT PRIMARY KEY,
                character_id INT NOT NULL,
                map_cell_id INT NOT NULL,
                claimed_at DATETIME NULL,
                status VARCHAR(32) NOT NULL DEFAULT "active"
            )
        ');

        $db->query('DROP TABLE IF EXISTS characters');
        $db->query('
            CREATE TABLE characters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cell_number INT NOT NULL DEFAULT 0,
                biome_id INT NOT NULL DEFAULT 1
            )
        ');

        $db->query('DROP TABLE IF EXISTS map');
        $db->query('
            CREATE TABLE map (
                id INT PRIMARY KEY,
                cell_number INT NOT NULL,
                coordinate_x INT NOT NULL DEFAULT 0,
                coordinate_y INT NOT NULL DEFAULT 0
            )
        ');

        // В боевой БД id == cell_number для всех клеток (проверено на проде: 1 000 000 строк,
        // расхождений 0) — фикстура повторяет этот инвариант, на нём стоит findActiveCell().
        foreach ([self::CELL_A, self::CELL_B, self::CELL_C] as $cell) {
            $db->query(
                'INSERT INTO map (id, cell_number, coordinate_x, coordinate_y) VALUES (?, ?, ?, ?)',
                [$cell, $cell, 10, 20],
            );
        }

        $db->query('INSERT INTO characters (id, cell_number) VALUES (?, ?)', [self::CHAR_ID, self::CELL_A]);
    }

    private function moveTo(int $cell): void
    {
        Database::connect('tests')->query(
            'UPDATE characters SET cell_number = ? WHERE id = ?',
            [$cell, self::CHAR_ID],
        );
    }

    private function claim(int $cell, string $status = 'active'): void
    {
        Database::connect('tests')->query(
            'INSERT INTO claimed_cells (character_id, map_cell_id, claimed_at, status) VALUES (?, ?, NOW(), ?)',
            [self::CHAR_ID, $cell, $status],
        );
    }

    public function testSingleBaseStillWorks(): void
    {
        $this->claim(self::CELL_A);

        $this->assertTrue((new PlayerStateService())->isCharacterOnBase(self::CHAR_ID));
        $this->assertTrue((new BaseCheckService())->checkBaseStatus(self::CHAR_ID)['isOnBase']);
    }

    /**
     * Сердце регрессии: две базы, игрок стоит на ВТОРОЙ (созданной позже).
     */
    public function testSecondBaseCountsAsHome(): void
    {
        $this->claim(self::CELL_A);
        $this->claim(self::CELL_B);
        $this->moveTo(self::CELL_B);

        $this->assertTrue(
            (new PlayerStateService())->isCharacterOnBase(self::CHAR_ID),
            'Стоя на своей второй базе, игрок обязан считаться дома — иначе события бьют его «как в поле».',
        );

        $status = (new BaseCheckService())->checkBaseStatus(self::CHAR_ID);
        $this->assertTrue($status['hasBase']);
        $this->assertTrue($status['isOnBase']);
    }

    public function testFieldIsNotHomeWithTwoBases(): void
    {
        $this->claim(self::CELL_A);
        $this->claim(self::CELL_B);
        $this->moveTo(self::CELL_C);

        $this->assertFalse((new PlayerStateService())->isCharacterOnBase(self::CHAR_ID));

        $status = (new BaseCheckService())->checkBaseStatus(self::CHAR_ID);
        $this->assertTrue($status['hasBase'], 'Базы есть, просто игрок не на них.');
        $this->assertFalse($status['isOnBase']);
    }

    public function testAbandonedBaseIsNotHome(): void
    {
        $this->claim(self::CELL_A, 'abandoned');
        $this->moveTo(self::CELL_A);

        $this->assertFalse(
            (new PlayerStateService())->isCharacterOnBase(self::CHAR_ID),
            'Брошенная клетка — не база.',
        );

        $status = (new BaseCheckService())->checkBaseStatus(self::CHAR_ID);
        $this->assertFalse($status['hasBase'], 'Строка со status=abandoned не должна считаться базой.');
        $this->assertFalse($status['isOnBase']);
    }

    public function testNoBaseAtAll(): void
    {
        $this->assertFalse((new PlayerStateService())->isCharacterOnBase(self::CHAR_ID));

        $status = (new BaseCheckService())->checkBaseStatus(self::CHAR_ID);
        $this->assertFalse($status['hasBase']);
        $this->assertFalse($status['isOnBase']);
    }

    public function testAbandonedSecondBaseDoesNotShadowActiveFirst(): void
    {
        // Первая строка — брошенная вторая база, вторая строка — живая первая.
        $this->claim(self::CELL_B, 'abandoned');
        $this->claim(self::CELL_A);
        $this->moveTo(self::CELL_A);

        $this->assertTrue((new PlayerStateService())->isCharacterOnBase(self::CHAR_ID));
        $this->assertTrue((new BaseCheckService())->checkBaseStatus(self::CHAR_ID)['isOnBase']);
    }
}
