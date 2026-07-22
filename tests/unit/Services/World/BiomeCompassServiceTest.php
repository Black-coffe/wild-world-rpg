<?php

declare(strict_types=1);

namespace Tests\Unit\Services\World;

use App\Services\World\BiomeCompassService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * Компас биомов (2026-07-22) — ответ на вопрос игрока «а где найти вулканы?».
 *
 * Главный риск фичи — ЗЕРКАЛО: север в этом мире это Y МЕНЬШЕ (Y=0 север, Y=999 юг,
 * см. NodeLevelCurve), а не больше. Перепутанная ось не падает ни одним тестом рендера
 * и не видна в логах — игрок просто уходит в противоположную сторону и снова идёт
 * спрашивать в чат. Поэтому оси проверяются явно.
 *
 * Чистая арифметика (direction / distanceBand / выбор блока) — без БД.
 *
 * @internal
 */
final class BiomeCompassServiceTest extends CIUnitTestCase
{
    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(BiomeCompassService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(new BiomeCompassService(), $args);
    }

    /** Север = -Y, юг = +Y, восток = +X, запад = -X. Зеркало здесь — худший исход фичи. */
    public function testDirectionAxesMatchWorldOrientation(): void
    {
        $far = 400;

        $this->assertSame('север', $this->call('direction', 0, -$far));
        $this->assertSame('юг', $this->call('direction', 0, $far));
        $this->assertSame('восток', $this->call('direction', $far, 0));
        $this->assertSame('запад', $this->call('direction', -$far, 0));
    }

    /** Диагонали должны читаться как «северо-восток», а не «север» + потерянная ось. */
    public function testDiagonalsAreNamedAsDiagonals(): void
    {
        $this->assertSame('северо-восток', $this->call('direction', 300, -300));
        $this->assertSame('юго-запад', $this->call('direction', -300, 300));
    }

    /**
     * Сильно вытянутое смещение — одна сторона света, а не диагональ:
     * «северо-восток» при 500 на восток и 60 на север сбивает с пути.
     */
    public function testStronglySkewedOffsetCollapsesToSingleSide(): void
    {
        $this->assertSame('восток', $this->call('direction', 500, -60));
        $this->assertSame('север', $this->call('direction', 60, -500));
    }

    /** Игрок внутри того же блока — направление бессмысленно. */
    public function testSameBlockGivesNoDirection(): void
    {
        $this->assertSame('здесь', $this->call('direction', 10, 10));
        $this->assertSame('здесь', $this->call('direction', 0, 0));
    }

    /** Расстояние — словами и монотонно: дальше не может звучать ближе. */
    public function testDistanceBandsAreMonotone(): void
    {
        $this->assertSame('недалеко', $this->call('distanceBand', 50.0));
        $this->assertSame('приличный путь', $this->call('distanceBand', 200.0));
        $this->assertSame('далеко', $this->call('distanceBand', 500.0));
        $this->assertSame('очень далеко', $this->call('distanceBand', 900.0));
    }

    /**
     * Ближайшее СКОПЛЕНИЕ, а не ближайшая одиночная клетка: блок-выброс рядом с игроком
     * не должен перебивать настоящее скопление. Иначе компас уводит в пустоту.
     */
    public function testOutlierBlockDoesNotBeatRealCluster(): void
    {
        $blocks = [
            '1:1' => 2,     // выброс под боком у игрока
            '8:2' => 3000,  // настоящее скопление на северо-востоке
            '8:3' => 900,
        ];

        $target = $this->call('nearestSignificantBlock', $blocks, 150, 150);
        $this->assertIsArray($target);
        [$tx, $ty] = $target;

        $this->assertGreaterThan(700, $tx, 'Компас указал не на скопление, а на одиночный выброс.');
        $this->assertLessThan(400, $ty);
    }

    /** Из двух настоящих скоплений выбирается ближнее к игроку, а не самое крупное. */
    public function testNearestOfTwoRealClustersWins(): void
    {
        $blocks = ['1:1' => 1000, '9:9' => 5000];

        $target = $this->call('nearestSignificantBlock', $blocks, 120, 120);
        $this->assertIsArray($target);
        [$tx, $ty] = $target;

        $this->assertSame(150, $tx);
        $this->assertSame(150, $ty);
    }

    /** Пустая раскладка не должна ронять легенду. */
    public function testEmptyBlocksAreHandled(): void
    {
        $this->assertNull($this->call('nearestSignificantBlock', ['1:1' => 0], 500, 500));
    }
}
