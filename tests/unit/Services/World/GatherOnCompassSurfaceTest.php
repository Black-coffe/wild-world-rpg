<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Services\Telegram\BotMenuService;
use App\Services\World\MoveSurfaceService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CallbackRoutes;

/**
 * Слайс «Второй шаг» (2026-07-24) — добыча обязана быть подписанной дверью на поверхности
 * ходьбы, а розетка — жить в ЕДИНОМ источнике.
 *
 * Триггер — прод-замер первой волны на новом холодном старте: 11 новичков из 14 пошли по
 * карте (79 шагов на когорту), а добычу нашёл ОДИН — единственный, кто ткнул безымянную
 * кнопку `🧑‍🌾 🛠️` в ряду направлений. Ядро игрового цикла не имело на пути новичка ни
 * одной двери, подписанной словом (в нижнем меню входа в добычу тоже нет, ADR-150).
 *
 * Тесты держат три инварианта, каждый из которых уже ломался в проде:
 *  1. кнопка ведёт в ЖИВОЙ роут (а не в никуда) — урок контрольного тапа;
 *  2. метка берётся из единого источника, а не литералом (иначе копия разойдётся);
 *  3. розетку рисует ОДИН код — экран шага не имеет собственной копии (близнец уже
 *     существовал: правка первого рендера не доехала бы до экрана, где новичок и живёт).
 *
 * @internal
 */
final class GatherOnCompassSurfaceTest extends CIUnitTestCase
{
    private const ALL_DIRECTIONS = [
        'move_dir_northwest', 'move_dir_north', 'move_dir_northeast',
        'move_dir_west', 'move_dir_east',
        'move_dir_southwest', 'move_dir_south', 'move_dir_southeast',
    ];

    /** ON: на поверхности ходьбы есть подписанная словом дверь в добычу. */
    public function testGatherButtonIsPresentAndLabelledWhenEnabled(): void
    {
        $rows = $this->rows(true);

        $gather = $this->findButton($rows, 'gather');
        $this->assertNotNull($gather, 'кнопки добычи нет на поверхности ходьбы при killswitch ON');

        // Подпись должна нести СЛОВО: ровно отсутствие слова и сделало прежнюю дверь
        // невидимой (две эмодзи читаются как украшение в ряду направлений).
        $this->assertMatchesRegularExpression(
            '/\p{Cyrillic}{3,}/u',
            $gather['text'],
            "метка добычи «{$gather['text']}» не содержит слова — это снова украшение, а не дверь"
        );
    }

    /** ON: хаб действий возвращает себе слово (в ряду из двух кнопок ширины хватает). */
    public function testActionsHubRegainsItsWordWhenEnabled(): void
    {
        $hub = $this->findButton($this->rows(true), 'characterActions');

        $this->assertNotNull($hub);
        $this->assertMatchesRegularExpression('/\p{Cyrillic}{3,}/u', $hub['text']);
    }

    /** Обе кнопки обязаны вести в реальный роут — иначе подписали дверь в стену. */
    public function testBothDoorsResolveToLiveRoutes(): void
    {
        $routes = new CallbackRoutes();

        foreach (['gather', 'characterActions'] as $action) {
            $this->assertNotNull(
                $routes->resolve($action),
                "callback «{$action}» не резолвится — кнопка ведёт в никуда"
            );
        }
    }

    /** ON: ряд направлений становится честной 3×3-розеткой, все 8 сторон на месте. */
    public function testRosetteStaysCompleteWhenEnabled(): void
    {
        $rows = $this->rows(true);

        foreach (self::ALL_DIRECTIONS as $dir) {
            $this->assertNotNull($this->findButton($rows, $dir), "потеряно направление {$dir}");
        }

        $this->assertCount(3, $rows[1], 'средний ряд розетки обязан стать 3-кнопочным (Запад · 🏕 · Восток)');
        $this->assertNotNull($this->findButton([$rows[1]], 'Base'), 'база должна остаться в центре розетки');
    }

    /** OFF: рендер byte-identical прежнему — добычи на поверхности нет, ряд из четырёх. */
    public function testDormantRenderIsUnchanged(): void
    {
        $rows = $this->rows(false);

        $this->assertNull($this->findButton($rows, 'gather'), 'при killswitch OFF добыча не должна появляться');
        $this->assertCount(3, $rows, 'при OFF рядов ровно три — розетка без ряда действий');
        $this->assertCount(4, $rows[1], 'при OFF средний ряд прежний, из четырёх кнопок');
        $this->assertNotNull($this->findButton($rows, 'characterActions'));

        foreach (self::ALL_DIRECTIONS as $dir) {
            $this->assertNotNull($this->findButton($rows, $dir), "потеряно направление {$dir}");
        }
    }

    /** Метки — из единого источника, а не литералом в сервисе. */
    public function testLabelsComeFromSingleSource(): void
    {
        $rows = $this->rows(true);

        $this->assertSame(BotMenuService::actionLabel('gather'), $this->findButton($rows, 'gather')['text']);
        $this->assertSame(BotMenuService::actionLabel('actionsHub'), $this->findButton($rows, 'characterActions')['text']);
        $this->assertSame('', BotMenuService::actionLabel('nope'), 'неизвестный ключ не должен выдумывать подпись');
    }

    /**
     * 🔴 Близнец мёртв: экран шага НЕ держит собственную копию розетки, а берёт её из
     * MoveSurfaceService. Скан исходника — потому что дрейф живёт именно в копии литералов:
     * до этого слайса оба экрана рисовали одни и те же ряды двумя независимыми списками.
     */
    public function testStepScreenHasNoOwnCopyOfTheRosette(): void
    {
        $src = (string) file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/MoveCharacterToDirectionAction.php'
        );

        $this->assertStringContainsString(
            'compassRows()',
            $src,
            'экран шага обязан брать розетку из единого источника MoveSurfaceService::compassRows()'
        );

        foreach (['move_dir_northwest', 'move_dir_southeast'] as $literal) {
            $this->assertStringNotContainsString(
                "'{$literal}'",
                $src,
                "экран шага снова держит собственную копию розетки ({$literal}) — близнец воскрес"
            );
        }
    }

    /**
     * @param list<array<int, array<string, string>>> $rows
     *
     * @return array<string, string>|null
     */
    private function findButton(array $rows, string $callback): ?array
    {
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (($button['callback_data'] ?? null) === $callback) {
                    return $button;
                }
            }
        }

        return null;
    }

    /**
     * Поверхность с подменённым killswitch (seam вместо похода в GameSettings).
     *
     * @return list<array<int, array<string, string>>>
     */
    private function rows(bool $enabled): array
    {
        $surface = new class ($enabled) extends MoveSurfaceService {
            public function __construct(private readonly bool $flag)
            {
                parent::__construct();
            }

            protected function gatherOnCompassEnabled(): bool
            {
                return $this->flag;
            }
        };

        return $surface->compassRows();
    }
}
