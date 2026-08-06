<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\GameSettingsModel;
use App\Services\Economy\CraftTradeService;
use App\Services\Economy\TradePricingService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\WarehouseSellBonusService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * Экраны лавки обязаны показывать то же число, которое проведёт сделка.
 *
 * До этого сервиса список категории и карточка количества печатали сырое значение из
 * БД, а деньги считал action подтверждения: продажа уходила ВНИЗ от показанного
 * (потолок множителя 1.0 плюс инвариант спреда), покупка — ВВЕРХ (пол множителя 1.25).
 * Оба расхождения работали против игрока, и увидеть их можно было только на экране
 * подтверждения. Тесты ниже фиксируют оба направления, потому что «показ = сделка»
 * — это обещание игроку, а не деталь реализации.
 *
 * @internal
 */
final class CraftTradeServiceTest extends CIUnitTestCase
{
    /** «Верстак 1го уровня» после ценового фикса 2026-08-06. */
    private const WORKBENCH_PRICE = 32000.0;

    protected function setUp(): void
    {
        parent::setUp();
        // GameSettingsService кеширует значение по ключу на 60 с — иначе соседние
        // тесты подсовывали бы друг другу первое прочитанное значение.
        service('cache')->clean();
    }

    private function neutralKarma(): float
    {
        return (float) (new GameBalance())->startingTradingKarma;
    }

    /**
     * Сервис на подменённых настройках и без единого обращения к БД: модели Склада
     * заменены анонимными наследниками, чей конструктор не зовёт родительский
     * (тот подключался бы к базе), а арифметика цены от базы не зависит.
     *
     * @param array<string,bool|float> $overrides
     */
    private function service(array $overrides = []): CraftTradeService
    {
        service('cache')->clean();

        $settingsModel = new class ($overrides) extends GameSettingsModel {
            /** @param array<string,bool|float> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->overrides)) {
                    return null; // → сервис берёт свой дефолт
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

        $settings = new GameSettingsService($settingsModel);

        $buildings = new class extends BuildingModel {
            public function __construct()
            {
            }
        };
        $charBuildings = new class extends CharacterBuildingModel {
            public function __construct()
            {
            }
        };

        return new CraftTradeService(
            new TradePricingService($settings),
            new WarehouseSellBonusService($settings, $buildings, $charBuildings),
        );
    }

    /**
     * Регрессия экранов продажи: показанное число = выплата.
     *
     * При нейтральной карме торговец платит базовую цену целиком, при нулевой — вдвое
     * меньше. Раньше оба случая печатались одинаково (32 000), и игрок с плохой кармой
     * узнавал про 16 000 только после сделки.
     */
    public function testSellDisplayEqualsWhatTransactionPays(): void
    {
        $svc = $this->service();

        foreach ([0.0, 50.0, $this->neutralKarma(), 200.0] as $karma) {
            $paid = $svc->sellUnitPrice(self::WORKBENCH_PRICE, $karma);
            $this->assertSame(
                (int) round($paid),
                $svc->displaySellPrice(self::WORKBENCH_PRICE, $karma),
                "показ и выплата разошлись при карме {$karma}",
            );
            $this->assertLessThanOrEqual(
                self::WORKBENCH_PRICE,
                $paid,
                'торговец не платит выше базовой цены — иначе крафт печатает золото',
            );
        }

        $this->assertEqualsWithDelta(
            self::WORKBENCH_PRICE,
            $svc->sellUnitPrice(self::WORKBENCH_PRICE, $this->neutralKarma()),
            0.001,
        );
        $this->assertEqualsWithDelta(
            self::WORKBENCH_PRICE * 0.5,
            $svc->sellUnitPrice(self::WORKBENCH_PRICE, 0.0),
            0.001,
        );
    }

    /**
     * Регрессия экранов покупки: наценка видна ДО тапа.
     *
     * Пол множителя покупки — 1.25, поэтому даже при идеальной карме игрок платит
     * больше числа из `sales.price`. Экраны показывали именно его.
     */
    public function testBuyDisplayIncludesVendorMarkup(): void
    {
        $svc  = $this->service();
        $base = 1000.0;

        $charged = $svc->buyUnitPrice($base, $this->neutralKarma());
        $this->assertEqualsWithDelta($base * 1.25, $charged, 0.001);
        $this->assertSame((int) round($charged), $svc->displayBuyPrice($base, $this->neutralKarma()));
        $this->assertGreaterThan($base, $charged, 'наценка обязана быть видна на экране');

        // Плохая карма кусается сильнее — и это тоже должно быть видно заранее.
        $this->assertGreaterThan($charged, $svc->buyUnitPrice($base, 0.0));
    }

    /** Спред: круг «купил → продал» остаётся убыточным при любой карме. */
    public function testBuyIsAlwaysAboveSellForSameItem(): void
    {
        $svc = $this->service();

        foreach ([0.0, 100.0, 200.0] as $karma) {
            $this->assertGreaterThan(
                $svc->sellUnitPrice(self::WORKBENCH_PRICE, $karma),
                $svc->buyUnitPrice(self::WORKBENCH_PRICE, $karma),
                "спред схлопнулся при карме {$karma}",
            );
        }
    }

    /** Складской бонус — внешний множитель поверх кармы, инвариант применяется после него. */
    public function testWarehouseBonusRaisesSellPriceButNotAboveBuy(): void
    {
        $svc     = $this->service();
        $karma   = $this->neutralKarma();
        $plain   = $svc->sellUnitPrice(self::WORKBENCH_PRICE, $karma, 1.0);
        $bonused = $svc->sellUnitPrice(self::WORKBENCH_PRICE, $karma, 1.10);

        $this->assertGreaterThanOrEqual($plain, $bonused);
        $this->assertLessThan($svc->buyUnitPrice(self::WORKBENCH_PRICE, $karma), $bonused);
    }

    /**
     * Подпись под ценой не обещает того, чего нет: про Склад сказано только когда
     * бонус реально включён killswitch'ем.
     */
    public function testSellHintMentionsWarehouseOnlyWhenBonusEnabled(): void
    {
        // Проверяем по одному сервису за раз: значение настройки кешируется по КЛЮЧУ
        // на 60 с, поэтому два сервиса, созданные подряд, читают первое прочитанное
        // значение (урок `feedback_gamesettings_cache_60s_flip_via_admin`).
        $on = $this->service(['economy.sell.warehouse_bonus.enabled' => true]);
        $this->assertStringContainsString('Склад', $on->sellPriceHint(1.0));
        // У владельца Склада формулировка уже в прошедшем времени — бонус применён.
        $this->assertStringContainsString('уже добавляет', $on->sellPriceHint(1.10));

        $off = $this->service(['economy.sell.warehouse_bonus.enabled' => false]);
        $this->assertStringNotContainsString('Склад', $off->sellPriceHint(1.0));
    }

    /**
     * Подписи уходят в Telegram с `parse_mode: Markdown`, который НЕ экранирует
     * разметку сам: непарный `_` или `*` рушит всё сообщение с 400, и игрок не
     * получает экран вовсе (урок `feedback_legacy_markdown_no_backslash_escape`).
     */
    public function testHintsAreMarkdownSafe(): void
    {
        $svc = $this->service();

        foreach ([$svc->sellPriceHint(1.0), $svc->sellPriceHint(1.10), $svc->buyPriceHint()] as $hint) {
            $this->assertSame(0, substr_count($hint, '_') % 2, "непарный _ в подписи: {$hint}");
            $this->assertSame(0, substr_count($hint, '*') % 2, "непарный * в подписи: {$hint}");
        }
    }

    /** Карма приходит из БД строкой, а персонаж — Entity: оба случая обязаны считаться. */
    public function testKarmaOfAcceptsDatabaseStringsAndGarbage(): void
    {
        $svc = $this->service();

        $this->assertSame(137.5, $svc->karmaOf(['trading_karma' => '137.50']));
        $this->assertSame(0.0, $svc->karmaOf(['trading_karma' => null]));
        $this->assertSame(0.0, $svc->karmaOf([]));
    }

    public function testNegativeBasePriceNeverPays(): void
    {
        $svc = $this->service();

        $this->assertSame(0.0, $svc->sellUnitPrice(-500.0, 100.0));
        $this->assertSame(0.0, $svc->buyUnitPrice(-500.0, 100.0));
    }
}
