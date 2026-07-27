<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\GameSettingsModel;
use App\Services\Economy\TradePricingService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-157 — инвариант спреда у NPC-торговца.
 *
 * До фикса продажа при нейтральной карме стоила столько же, сколько покупка,
 * складской бонус (ADR-085) делал её дороже, а карма росла от суммы продажи без
 * потолка. На проде это дало карму 15 592 при нейтрали 100 и 77.4 млн золота
 * одному персонажу.
 *
 * @internal
 */
final class TradePricingServiceTest extends CIUnitTestCase
{
    /** Карма из реального прод-инцидента. */
    private const PROD_EXPLOIT_KARMA = 15592.14;

    /** «Верстак 1го уровня» — база у торговца; себестоимость крафта 20 000 золота. */
    private const WORKBENCH_BASE_PRICE = 132000.0;

    protected function setUp(): void
    {
        parent::setUp();
        // Настройки кешируются на 60 с по ключу — чистим, чтобы соседние тесты
        // не подсовывали друг другу значения.
        service('cache')->clean();
    }

    /**
     * @param array<string,float> $overrides
     */
    private function service(array $overrides = []): TradePricingService
    {
        $model = new class ($overrides) extends GameSettingsModel {
            /** @param array<string,float> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->overrides)) {
                    return null; // → сервис берёт свой дефолт
                }

                return [
                    'setting_key' => $key,
                    'value_type'  => 'float',
                    'value_float' => $this->overrides[$key],
                ];
            }
        };

        return new TradePricingService(new GameSettingsService($model));
    }

    /**
     * Главный инвариант: продать всегда дешевле, чем купить — при любой карме,
     * любой базовой цене и любом внешнем бонусе.
     */
    public function testSellIsAlwaysCheaperThanBuy(): void
    {
        $svc = $this->service();

        foreach ([-500.0, -100.89, 0.0, 1.0, 50.0, 100.0, 150.0, 200.0, 1195.76, self::PROD_EXPLOIT_KARMA] as $karma) {
            foreach ([1.0, 105.0, 260.05, self::WORKBENCH_BASE_PRICE] as $base) {
                foreach ([1.0, 1.1, 2.0, 10.0] as $extra) {
                    $sell = $svc->sellUnitPrice($base, $karma, $extra);
                    $buy  = $svc->buyUnitPrice($base, $karma);

                    $this->assertLessThan(
                        $buy,
                        $sell,
                        "Круг «купил → продал» стал безубыточным: карма {$karma}, база {$base}, бонус {$extra}"
                    );
                }
            }
        }
    }

    /** Складской бонус (ADR-085) не может перевернуть спред — инвариант идёт последним. */
    public function testWarehouseBonusCannotInvertSpread(): void
    {
        $svc = $this->service();

        $withBonus = $svc->sellUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0, 1.10);
        $buy       = $svc->buyUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0);

        $this->assertLessThanOrEqual($buy * 0.90 + 0.0001, $withBonus);
    }

    /**
     * Честный игрок с нейтральной кармой продаёт по базовой цене — ровно как
     * до фикса. Фикс снимает премию, а не режет доход.
     */
    public function testNeutralKarmaSellsAtBasePrice(): void
    {
        $svc = $this->service();

        $this->assertEqualsWithDelta(
            self::WORKBENCH_BASE_PRICE,
            $svc->sellUnitPrice(self::WORKBENCH_BASE_PRICE, 100.0),
            0.001
        );
    }

    /** Регрессия прод-инцидента: раздутая карма больше не даёт 10.5× базовой. */
    public function testProdExploitKarmaNoLongerInflatesSellPrice(): void
    {
        $svc = $this->service();

        $sell = $svc->sellUnitPrice(self::WORKBENCH_BASE_PRICE, self::PROD_EXPLOIT_KARMA, 1.10);

        // До фикса: 132000 × 10.5 × 1.1 = 1 524 600 (реально наблюдалось 1 386 000).
        // После: карма зажата, премия за карму снята; выше базовой цены остаётся
        // только намеренный складской бонус ADR-085 (+10%).
        $this->assertLessThanOrEqual(self::WORKBENCH_BASE_PRICE * 1.10, $sell);
        $this->assertLessThan($svc->buyUnitPrice(self::WORKBENCH_BASE_PRICE, self::PROD_EXPLOIT_KARMA), $sell);
    }

    public function testKarmaIsClampedToBounds(): void
    {
        $svc = $this->service();

        $this->assertSame(200.0, $svc->normalizeKarma(self::PROD_EXPLOIT_KARMA));
        $this->assertSame(0.0, $svc->normalizeKarma(-100.89));
        $this->assertSame(137.5, $svc->normalizeKarma(137.5));
    }

    /**
     * Админ не может отключить инвариант, выставив нулевой спред — сервис
     * держит собственный пол.
     */
    public function testZeroSpreadSettingStillLeavesRoundTripUnprofitable(): void
    {
        $svc = $this->service([TradePricingService::KEY_MIN_SPREAD_PCT => 0.0]);

        $sell = $svc->sellUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0, 5.0);
        $buy  = $svc->buyUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0);

        $this->assertLessThan($buy, $sell);
    }

    /**
     * И не может — выставив множители так, чтобы продажа была выгоднее покупки.
     */
    public function testHostileSettingsCannotInvertSpread(): void
    {
        $svc = $this->service([
            TradePricingService::KEY_SELL_MULT_MAX => 1.50,
            TradePricingService::KEY_BUY_MULT_MIN  => 1.05,
        ]);

        $sell = $svc->sellUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0, 1.10);
        $buy  = $svc->buyUnitPrice(self::WORKBENCH_BASE_PRICE, 200.0);

        $this->assertLessThan($buy, $sell);
    }

    /** Настройки действительно читаются (не захардкожены мимо GameSettings). */
    public function testSettingsAreHonoured(): void
    {
        $svc = $this->service([TradePricingService::KEY_KARMA_MAX => 400.0]);

        $this->assertSame(400.0, $svc->normalizeKarma(10000.0));
    }
}
