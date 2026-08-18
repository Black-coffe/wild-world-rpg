<?php

declare(strict_types=1);

namespace Tests\Unit\World;

use App\Services\World\BiomePalette;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Гейт личной карты исследованного («🔍 Что я открыл»).
 *
 * Вопрос игрока (Анжела, 18.08.2026): «нет ли ресурса, показывающего открытую карту?»
 * Не было: `explored_cells` копился с первого шага и не показывался нигде.
 *
 * ⚠️ Сам рендер тут не проверяется и проверен быть не может: он идёт по таблицам
 * `map` + `explored_cells`, а в тестовой базе `map` нет вовсе (урок
 * feedback_verify_render_on_db_with_real_world_data). Рендер смокается на реальных
 * данных командой `php spark map:explored --char=<id>`. Здесь — достижимость экрана,
 * палитра и то, что вход не потеряется.
 */
final class ExploredMapTest extends CIUnitTestCase
{
    public function testScreenIsRoutedAndReachable(): void
    {
        $routes = new CallbackRoutes();

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\ExploredMapAction::class,
            $routes->resolve('exploredMap'),
            'callback `exploredMap` не резолвится — кнопка будет мёртвой.'
        );

        $overview = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/MapOverviewAction.php'
        );
        $this->assertStringContainsString(
            "'callback_data' => 'exploredMap'",
            $overview,
            'Вход на личную карту обязан стоять на экране «🗺 Обзор» — иначе фича снова невидима.'
        );
    }

    /**
     * Палитра — одна точка правды на все растровые карты. Девять биомов мира должны
     * быть покрыты, а неизвестный id — не ронять рендер.
     */
    public function testPaletteCoversAllBiomesAndFallsBack(): void
    {
        for ($biomeId = 1; $biomeId <= 9; $biomeId++) {
            $this->assertArrayHasKey($biomeId, BiomePalette::COLORS, "Биом {$biomeId} без цвета.");
        }

        $this->assertSame(BiomePalette::FALLBACK, BiomePalette::for(999), 'Неизвестный биом обязан давать fallback, а не падение.');

        foreach (BiomePalette::COLORS as $biomeId => $rgb) {
            $this->assertCount(3, $rgb, "Цвет биома {$biomeId} должен быть [R, G, B].");
            foreach ($rgb as $channel) {
                $this->assertGreaterThanOrEqual(0, $channel);
                $this->assertLessThanOrEqual(255, $channel);
            }
        }
    }

    /**
     * Мёртвый предшественник удалён — иначе рядом живут два рисователя карты
     * исследованного, и следующий разработчик правит тот, который никто не зовёт.
     */
    public function testDeadMiniMapServiceIsGone(): void
    {
        $this->assertFileDoesNotExist(
            APPPATH . 'Services/World/MiniMapService.php',
            'MiniMapService не имел ни одного вызывающего и заменён ExploredMapService.'
        );
    }
}
