<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\GameSettingsModel;
use App\Services\Economy\VendorDailyLimitService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-157 шаг 2 — суточный лимит выкупа у торговца.
 *
 * Закрывает остаточный кран: у 22 позиций из 39 ВСЕ входы рецепта покупаются у того
 * же торговца без лимита стока (худший случай — «Рыбные консервы»: входы ~5.6 золота,
 * выкуп ~374, то есть ×67 за круг).
 *
 * @internal
 */
final class VendorDailyLimitServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /**
     * @param array<string,float|bool>       $overrides
     * @param list<array{price: float, at: int}> $window сделки окна, от старой к свежей
     */
    private function service(float $alreadySold, array $overrides = [], array $window = []): VendorDailyLimitService
    {
        $settingsModel = new class ($overrides) extends GameSettingsModel {
            /** @param array<string,float|bool> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->overrides)) {
                    return null;
                }
                $value = $this->overrides[$key];

                return [
                    'setting_key' => $key,
                    'value_type'  => is_bool($value) ? 'bool' : 'float',
                    'value_bool'  => is_bool($value) ? ($value ? 1 : 0) : null,
                    'value_float' => is_bool($value) ? null : $value,
                ];
            }
        };

        return new class (new GameSettingsService($settingsModel), $alreadySold, $window) extends VendorDailyLimitService {
            /** @param list<array{price: float, at: int}> $window */
            public function __construct(GameSettingsService $settings, private float $alreadySold, private array $window)
            {
                parent::__construct($settings, null);
            }

            public function soldLast24h(int $characterId): float
            {
                return $this->alreadySold; // подменяем БД-чтение
            }

            /** @return list<array{price: float, at: int}> */
            protected function salesInWindow(int $characterId): array
            {
                return $this->window; // второй seam — момент открытия лавки
            }
        };
    }

    public function testAllowsSaleWithinCap(): void
    {
        $svc = $this->service(10000.0);

        $this->assertTrue($svc->allows(1, 5000.0));
        $this->assertEqualsWithDelta(40000.0, $svc->remaining(1), 0.001);
    }

    /**
     * Лимит закрывает СЛЕДУЮЩУЮ сделку, а не ту, что превысит потолок. Иначе
     * дорогой штучный предмет становится непродаваемым навсегда.
     */
    public function testSaleLargerThanRemainderStillGoesThrough(): void
    {
        $svc = $this->service(48000.0);

        $this->assertTrue($svc->allows(1, 5000.0), 'сделка сверх остатка должна пройти — она последняя');
        $this->assertTrue($svc->allows(1, 2000.0));
    }

    /**
     * Регрессия прод-случая: «Верстак 1» стоит 132 000 при потолке 50 000.
     * Проверка «сделка должна помещаться в остаток» отрезала бы его владельца
     * от лавки без всякого пути обхода.
     */
    public function testExpensiveSingleItemIsAlwaysSellableOnFreshDay(): void
    {
        $svc = $this->service(0.0);

        $this->assertTrue($svc->allows(1, 132000.0));
    }

    public function testBlocksEverythingWhenCapAlreadyReached(): void
    {
        $svc = $this->service(50000.0);

        $this->assertSame(0.0, $svc->remaining(1));
        $this->assertFalse($svc->allows(1, 1.0));
    }

    /** Killswitch снимает лимит полностью — путь отката без редеплоя. */
    public function testDisabledMeansNoLimit(): void
    {
        $svc = $this->service(10_000_000.0, [VendorDailyLimitService::KEY_ENABLED => false]);

        $this->assertSame(INF, $svc->remaining(1));
        $this->assertTrue($svc->allows(1, 999_999_999.0));
    }

    public function testCapIsTunable(): void
    {
        $svc = $this->service(0.0, [VendorDailyLimitService::KEY_CAP => 1234.0]);
        $this->assertEqualsWithDelta(1234.0, $svc->remaining(1), 0.001);

        // Потолок исчерпан ровно по своему значению, а не по дефолтному.
        $spent = $this->service(1234.0, [VendorDailyLimitService::KEY_CAP => 1234.0]);
        $this->assertSame(0.0, $spent->remaining(1));
        $this->assertFalse($spent->allows(1, 1.0));
    }

    /**
     * Регрессия остаточного крана: «Рыбные консервы» дают ~368 золота профита с
     * единицы при выкупе ~374. Лимит обязан упереть цикл в потолок за сутки.
     */
    public function testFishPreserveCycleHitsCapWithinOneDay(): void
    {
        $revenuePerUnit = 374.0;
        $sold           = 0.0;
        $units          = 0;

        while ($units < 100000) {
            $svc = $this->service($sold);
            if (! $svc->allows(1, $revenuePerUnit)) {
                break;
            }
            $sold += $revenuePerUnit;
            $units++;
        }

        // Перерасход ограничен стоимостью одного предмета — это цена того, что
        // дорогая штучная вещь остаётся продаваемой.
        $this->assertLessThan(150, $units, 'цикл не упёрся в суточный потолок');
        $this->assertLessThanOrEqual(50000.0 + $revenuePerUnit, $sold);
    }

    /**
     * Замер прода 2026-08-06: персонаж 1115 проносит «Верстак 1» ×5 = 660 000 одной
     * сделкой через потолок 50 000 — потому что стек уходит целиком. Фиксируем дыру
     * тестом, чтобы её закрытие было осознанным балансовым решением, а не сюрпризом.
     */
    public function testWholeStackOvershootsCapInOneDeal(): void
    {
        $svc = $this->service(0.0);

        $this->assertTrue($svc->allows(1, 660000.0), 'стек уходит целиком — потолок сделку не режет');
        // После неё дверь закрыта, но 13 потолков уже прошли.
        $this->assertFalse($this->service(660000.0)->allows(1, 1.0));
    }

    /**
     * Окно скользящее, поэтому «приходи завтра» — неправда. Освобождение наступает,
     * когда из последних 24 часов выпадет достаточно старых сделок.
     */
    public function testReliefTimeIsWhenOldestSalesLeaveTheWindow(): void
    {
        $base = 1_700_000_000;
        // 30 000 двадцать часов назад + 25 000 час назад = 55 000 при потолке 50 000.
        $window = [
            ['price' => 30000.0, 'at' => $base - 20 * 3600],
            ['price' => 25000.0, 'at' => $base - 3600],
        ];
        $svc = $this->service(55000.0, [], $window);

        // Выпадения ПЕРВОЙ сделки уже хватает: 55 000 − 30 000 = 25 000 < 50 000.
        $this->assertSame($base - 20 * 3600 + 86400, $svc->reliefAt(1));
    }

    public function testReliefIsNullWhileCapNotReached(): void
    {
        $this->assertNull($this->service(10000.0)->reliefAt(1));
        $this->assertNull($this->service(10_000_000.0, [VendorDailyLimitService::KEY_ENABLED => false])->reliefAt(1));
    }

    /**
     * Отказ обязан называть причину числами: раньше он говорил только «монеты
     * кончились», и игрок не мог отличить исчерпанный лимит от поломки.
     */
    public function testRefusalTextNamesCapSoldAndTail(): void
    {
        $window = [['price' => 60000.0, 'at' => 1_700_000_000]];
        $text   = $this->service(60000.0, [], $window)->refusalText(1, 'Твоя экипировка никуда не делась.');

        $this->assertStringContainsString('50000', $text, 'потолок не назван');
        $this->assertStringContainsString('60000', $text, 'уже выкупленное не названо');
        $this->assertStringContainsString('24 часа', $text, 'окно не объяснено');
        $this->assertStringContainsString('Твоя экипировка никуда не делась.', $text);
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные звёздочки ломают Markdown');
    }

    /** Тому, кто сегодня не продавал, лимита на карточке быть не должно. */
    public function testBudgetHintSilentForPlayersWhoDidNotSellToday(): void
    {
        $this->assertSame('', $this->service(0.0)->budgetHint(1));
        $this->assertSame('', $this->service(40000.0, [VendorDailyLimitService::KEY_ENABLED => false])->budgetHint(1));
    }

    /**
     * 🔴 Подсказка НЕ должна обещать зажим количества: сделка проходит целиком.
     * Число «осталось N» на экране означало бы обещание, которого сделка не держит.
     */
    public function testBudgetHintShowsRunningTotalNotAPromiseOfClamping(): void
    {
        $hint = $this->service(40000.0)->budgetHint(1);

        $this->assertStringContainsString('40000', $hint);
        $this->assertStringContainsString('50000', $hint);
        $this->assertStringContainsString('целиком', $hint, 'игрок должен знать, что сделка не режется');
        $this->assertSame(0, substr_count($hint, '*') % 2, 'непарные звёздочки ломают Markdown');
    }

    public function testBudgetHintAnnouncesClosureWithTimeWhenCapSpent(): void
    {
        $window = [['price' => 55000.0, 'at' => 1_700_000_000]];
        $hint   = $this->service(55000.0, [], $window)->budgetHint(1);

        $this->assertStringContainsString('кончились', $hint);
        $this->assertStringContainsString(date('H:i', 1_700_000_000 + 86400), $hint);
        $this->assertSame(0, substr_count($hint, '*') % 2);
    }
}
