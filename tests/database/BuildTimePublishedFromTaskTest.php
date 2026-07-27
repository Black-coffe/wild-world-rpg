<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Buildings\BuildDurationService;
use App\Services\WikiContentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-161 — справочные поверхности публикуют время стройки из строки `tasks`, по которой
 * стройка идёт на самом деле, а не из декоративной колонки `buildings.construction_time`.
 *
 * Колонка разошлась с задачей у 14 построек из 16 (замер прода 2026-07-27) — причём в ОБЕ
 * стороны: девять завышали (Склад публиковал 180 при фактических 74), пять занижали
 * (Колючая ограда публиковала 45 при фактических 70). Тест держит обе стороны: одного
 * «завышения» мало, чтобы поймать регресс, при котором кто-нибудь снова начнёт читать
 * колонку.
 *
 * @internal
 */
final class BuildTimePublishedFromTaskTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect('tests');

        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('CREATE TABLE buildings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name_ru VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NOT NULL,
            description TEXT NULL,
            building_type VARCHAR(50) NULL,
            hp INT NOT NULL DEFAULT 0,
            construction_time INT NOT NULL DEFAULT 0,
            tax INT NOT NULL DEFAULT 0,
            level INT NOT NULL DEFAULT 1,
            min_character_level INT NOT NULL DEFAULT 1,
            effects TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->query('DROP TABLE IF EXISTS tasks');
        $db->query('CREATE TABLE tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            min_duration INT NOT NULL DEFAULT 0,
            max_duration INT NOT NULL DEFAULT 0,
            handler_key VARCHAR(100) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Числа — прод-значения на 2026-07-27.
        $db->query(
            "INSERT INTO buildings (name_ru, name_en, building_type, hp, construction_time, tax, level, min_character_level)
             VALUES ('Склад', 'Warehouse', 'resource', 100, 180, 5, 1, 1),
                    ('Колючая ограда', 'BarbedFence', 'military', 60, 45, 0, 1, 1),
                    ('Ручная скважина', 'HandPump', 'resource', 50, 60, 2, 1, 1)"
        );
        // Скважина намеренно БЕЗ строки задачи — проверяем, что число не выдумывается.
        $db->query(
            "INSERT INTO tasks (name, min_duration, max_duration, handler_key)
             VALUES ('startBuildWarehouse', 56, 74, 'generic_building'),
                    ('buildBarbedFence', 45, 70, 'generic_building')"
        );

        BuildDurationService::resetRangeCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS buildings');
        $db->query('DROP TABLE IF EXISTS tasks');
        BuildDurationService::resetRangeCache();
        parent::tearDown();
    }

    public function testPublishedMinutesComesFromTaskNotFromDecorativeColumn(): void
    {
        $svc = new BuildDurationService();

        // Колонка говорила 180 — задача идёт 74. Публикуем задачу.
        $this->assertSame(74, $svc->publishedMinutes('Warehouse'));
        $this->assertNotSame(180, $svc->publishedMinutes('Warehouse'));

        // Обратная сторона расхождения: колонка ЗАНИЖАЛА (45 против фактических 70).
        $this->assertSame(70, $svc->publishedMinutes('BarbedFence'));
        $this->assertNotSame(45, $svc->publishedMinutes('BarbedFence'));
    }

    public function testPublishedMinutesIsFullTimeNotFastEdgeOfRange(): void
    {
        // 🔴 Публикуется max, а не min и не диапазон: потолок счёта статов 2000, а лучший
        // счёт на проде 115 (5.75%) — быстрый край задачи недостижим никому, обещать его
        // значило бы врать в другую сторону (ADR-161).
        $svc = new BuildDurationService();

        $this->assertSame(['min' => 56, 'max' => 74], $svc->rangeFor('Warehouse'));
        $this->assertSame(74, $svc->publishedMinutes('Warehouse'), 'Публикуем полное время, а не время недостижимого ветерана');
    }

    public function testBuildingWithoutTaskRowPublishesNothing(): void
    {
        $svc = new BuildDurationService();

        $this->assertNull($svc->rangeFor('HandPump'));
        $this->assertNull($svc->publishedMinutes('HandPump'), 'Нет задачи — время не выдумывается');
        $this->assertNull($svc->publishedMinutes('NoSuchBuilding'));
    }

    public function testWikiProjectionPublishesTaskTime(): void
    {
        $rows = [];
        foreach ((new WikiContentService())->buildings() as $row) {
            $nameEn = is_string($row['name_en'] ?? null) ? $row['name_en'] : '';
            $rows[$nameEn] = $row;
        }

        $this->assertSame(74, $rows['Warehouse']['construction_time'] ?? null);
        $this->assertSame(70, $rows['BarbedFence']['construction_time'] ?? null);

        // Скважина без задачи: ключ есть, значение null → бейдж «Стройка, мин» на карточке
        // просто не рисуется (view пропускает не-скаляры). `??` тут писать нельзя — он
        // схлопывает null и превращает проверку в декоративную.
        $this->assertArrayHasKey('HandPump', $rows);
        $this->assertArrayHasKey('construction_time', $rows['HandPump']);
        $this->assertNull($rows['HandPump']['construction_time']);
    }
}
