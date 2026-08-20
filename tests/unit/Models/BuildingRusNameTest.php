<?php

namespace Tests\Unit\Models;

use App\Models\BuildingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Гард против англоязычной подписи постройки в player-facing тексте.
 *
 * Колонка БД — `buildings.name_ru`, а ключ рецепта в `Config\Buildings` — `name_rus`.
 * Экраны, читавшие строку БД по `name_rus`, молча показывали игроку «BlastFurnace» и
 * «Laboratory» (жалоба игрока 2026-08-20: «где это добыть, я такого не встречал»).
 * Резолвер обязан понимать оба ключа и падать в fallback только когда имени нет вовсе.
 *
 * @internal
 */
final class BuildingRusNameTest extends CIUnitTestCase
{
    public function testReadsDbColumnNameRu(): void
    {
        $row = ['id' => 2, 'name_en' => 'BlastFurnace', 'name_ru' => 'Доменная печь'];

        $this->assertSame('Доменная печь', BuildingModel::rusName($row, 'BlastFurnace'));
    }

    public function testReadsConfigKeyNameRus(): void
    {
        $recipe = ['name_en' => 'Laboratory', 'name_rus' => 'Лаборатория'];

        $this->assertSame('Лаборатория', BuildingModel::rusName($recipe, 'Laboratory'));
    }

    public function testEmptyNameFallsBackInsteadOfPrintingBlank(): void
    {
        $row = ['name_en' => 'Laboratory', 'name_ru' => ''];

        $this->assertSame('Laboratory', BuildingModel::rusName($row, 'Laboratory'));
    }

    public function testMissingRowFallsBack(): void
    {
        $this->assertSame('Workshop', BuildingModel::rusName(null, 'Workshop'));
    }
}
