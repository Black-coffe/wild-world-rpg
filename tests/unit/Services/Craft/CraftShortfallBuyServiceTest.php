<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Entities\ResourceEntity;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\GameSettingsModel;
use App\Models\ResourceModel;
use App\Services\Craft\CraftShortfallBuyService;
use App\Services\Economy\CraftTradeService;
use App\Services\Economy\TradePricingService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\ResourcePoolService;
use App\Services\Player\Trade\ResourceTradeService;
use App\Services\Player\WarehouseSellBonusService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GameBalance;

/**
 * `docs/specs/craft-shortfall-buy/plan.md` §Contracts — единственный источник чисел
 * докупки недостающего сырья у торговца. Story 08 (экран) и 09 (сделка) строятся
 * против этого контракта, поэтому граничные значения тут — не формальность.
 *
 * @internal
 */
final class CraftShortfallBuyServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /**
     * @param array<string,float|int|bool> $settings ключ (без префикса craft.shortfall_buy.) → значение
     * @param array<string,array{id:int,is_tradeable?:int,buy_price?:float,level_required?:int}> $resources
     * @param array<string,int> $backpack имя ресурса → доступно
     * @param array<string,array{id:int,name_rus:string,price?:float}> $craftedRows name_eng → строка crafted_items
     * @param array<int,array{quantity:int}> $craftedLog crafted_item_id => лог по персонажу
     */
    private function service(
        array $settings = [],
        array $resources = [],
        array $backpack = [],
        array $craftedRows = [],
        array $craftedLog = []
    ): CraftShortfallBuyService {
        $settingsModel = new class ($settings) extends GameSettingsModel {
            /** @param array<string,float|int|bool> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                $short = str_replace('craft.shortfall_buy.', '', $key);
                if (! array_key_exists($short, $this->values)) {
                    return null;
                }
                $value = $this->values[$short];
                if (is_bool($value)) {
                    return ['setting_key' => $key, 'value_type' => 'bool', 'value_bool' => $value ? 1 : 0];
                }

                return ['setting_key' => $key, 'value_type' => 'int', 'value_int' => (int) $value];
            }
        };

        $resourceModel = new class ($resources) extends ResourceModel {
            /** @param array<string,array{id:int,is_tradeable?:int,buy_price?:float,level_required?:int}> $resources */
            public function __construct(private array $resources)
            {
                parent::__construct();
            }

            public function getResourceByName($name)
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name] + [
                    'name'         => $name,
                    'is_tradeable' => 1,
                    'buy_price'    => 0.0,
                    'level_required' => 0,
                ]);
            }
        };

        $pool = new class ($backpack) extends ResourcePoolService {
            /** @param array<string,int> $backpack */
            public function __construct(private array $backpack)
            {
                parent::__construct();
            }

            public function availableByName(int $characterId, string $resourceName): int
            {
                return $this->backpack[$resourceName] ?? 0;
            }
        };

        $craftedItems = new class ($craftedRows) extends CraftedItemsModel {
            /** @param array<string,array{id:int,name_rus:string}> $rows */
            public function __construct(private array $rows)
            {
                parent::__construct();
            }

            public function getRowByName($itemName)
            {
                return $this->rows[$itemName] ?? null;
            }
        };

        $craftedLogModel = new class ($craftedLog) extends CraftedItemsLogModel {
            /** @param array<int,array{quantity:int}> $log */
            public function __construct(private array $log)
            {
                parent::__construct();
            }

            public function getItemByCraftedItemIdAndCharacterId(int $craftedItemId, int $characterId)
            {
                return $this->log[$craftedItemId] ?? null;
            }
        };

        $gameSettings = new GameSettingsService($settingsModel);

        // Гейт рентабельности обязан считать ЖИВУЮ выручку прилавка (CraftTradeService), а не
        // выдуманное число — те же классы, что и настоящая продажа крафта. Тот же
        // `$settingsModel` не знает про ключи `economy.trade.*`/`economy.sell.*` (`findByKey`
        // вернёт `null` только для префикса `craft.shortfall_buy.`, для остальных — тоже `null`,
        // раз их нет в `$settings`), поэтому обе службы падают на свои дефолты: складской
        // бонус выключен (killswitch off), карма 100 (нейтральная, `GameBalance::$startingTradingKarma`)
        // даёт `sellUnitPrice(price, 100) === price` — детерминированное число для теста.
        $craftTrade = new CraftTradeService(
            new TradePricingService($gameSettings, new GameBalance()),
            new WarehouseSellBonusService($gameSettings)
        );

        return new CraftShortfallBuyService(
            $resourceModel,
            $pool,
            new ResourceTradeService(),
            $gameSettings,
            $craftedItems,
            $craftedLogModel,
            $craftTrade
        );
    }

    /** Карма, при которой `sellUnitPrice(price, …) === price` — детерминированный якорь тестов. */
    private function neutralKarma(): float
    {
        return (float) (new GameBalance())->startingTradingKarma;
    }

    private const DEFAULT_SETTINGS = [
        'enabled'             => true,
        'base_markup_pct'     => 15,
        'slope_markup_pct'    => 35,
        'min_markup_gold'     => 1,
        'profit_gate_enabled' => false,
    ];

    public function testKillswitchOffRefusesWithoutCrash(): void
    {
        $svc = $this->service(['enabled' => false]);

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Базальт' => 1]], 1);

        $this->assertFalse($quote->available);
        $this->assertSame('killswitch_off', $quote->refusal);
        $this->assertSame(0, $quote->total);
        $this->assertSame([], $quote->lines);
    }

    /** Флагманский случай: не хватает ровно одной штуки за 1💰 — «+15%» на экране должно быть правдой. */
    public function testMarkupPctIsActualNotNominalOnSingleUnitGap(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Базальт' => ['id' => 1, 'buy_price' => 1.0]],
            backpack: ['Базальт' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Базальт' => 1]], 1);

        // base=1, ceil(1*1.15)=2, base+min_markup_gold=2 → total=2.
        $this->assertSame(2, $quote->total);
        // Номинал в настройках — 15%, но факт от 1 до 2 — это +100%, и печатать надо его.
        $this->assertSame(100, $quote->markupPct);
        $this->assertNotSame(15, $quote->markupPct);
    }

    public function testShareIsComputedInMoneyNotInUnits(): void
    {
        // Не хватает 1 ед. дорогого сырья (100💰) и 50 ед. дешёвого (0.1💰 каждая = 5💰).
        // По штукам дешёвого сырья "больше", но по деньгам основной вклад — дорогое.
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            [
                'Артефакт'  => ['id' => 1, 'buy_price' => 100.0],
                'Водоросли' => ['id' => 2, 'buy_price' => 0.1],
            ],
            backpack: ['Артефакт' => 0, 'Водоросли' => 0]
        );

        $quote = $svc->quote(
            ['id' => 1, 'level' => 5, 'gold' => 100000],
            ['resources' => ['Артефакт' => 1, 'Водоросли' => 50]],
            1
        );

        // base = 100 + 5 = 105 = full (все позиции недостающие целиком).
        $this->assertEqualsWithDelta(105.0, $quote->baseCost, 0.001);
        $this->assertEqualsWithDelta(105.0, $quote->fullCost, 0.001);
        $this->assertEqualsWithDelta(1.0, $quote->share, 0.001);
    }

    public function testMinMarkupGoldPreventsRoundingToZeroOnCheapPosition(): void
    {
        $svc = $this->service(
            ['enabled' => true, 'base_markup_pct' => 5, 'slope_markup_pct' => 0, 'min_markup_gold' => 3, 'profit_gate_enabled' => false],
            ['Вода' => ['id' => 1, 'buy_price' => 0.1]],
            backpack: ['Вода' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Вода' => 1]], 1);

        // base = 0.1, ceil(0.1*1.05)=1, base+min_markup_gold=0.1+3=3.1 → пол округляется ВВЕРХ
        // (fix-03), не усекается: 4, а не 3 — без пола наценка (0.005) утонула бы в округлении.
        $this->assertSame(4, $quote->total);
        $this->assertGreaterThanOrEqual(0.1 + 3, (float) $quote->total);
    }

    /**
     * fix-03 (мелкая находка 12): пол наценки никогда не даёт итог ниже своего обещания
     * `base + min_markup_gold`. `(int) max(...)` без внутреннего `ceil()` на каждом слагаемом
     * усекал 2.6 до 2 — ниже собственного пола; здесь пол — 2.6, а не 3.1, чтобы убедиться,
     * что чинит именно усечение пола, а не совпадение с копеечным сценарием выше.
     */
    public function testMarkupFloorNeverTruncatesBelowPromisedMinimum(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Смола' => ['id' => 1, 'buy_price' => 1.6]],
            backpack: ['Смола' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Смола' => 1]], 1);

        // base=1.6, min_markup_gold=1 → пол = 2.6. ceil(2.6) = 3, а старое усечение дало бы 2.
        $this->assertSame(3, $quote->total);
        $this->assertGreaterThanOrEqual(1.6 + 1, (float) $quote->total);
    }

    /**
     * fix-03 (мелкая находка 13): процент на копеечной позиции больше не абсурден — база
     * прижата снизу к 1 при расчёте процента, поэтому `2900%` (и хуже — `3900%` после починки
     * пола) не появляется на экране.
     */
    public function testMarkupPctStaysSaneOnPennyPosition(): void
    {
        $svc = $this->service(
            ['enabled' => true, 'base_markup_pct' => 5, 'slope_markup_pct' => 0, 'min_markup_gold' => 3, 'profit_gate_enabled' => false],
            ['Вода' => ['id' => 1, 'buy_price' => 0.1]],
            backpack: ['Вода' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Вода' => 1]], 1);

        $this->assertLessThan(1000, $quote->markupPct);
        $this->assertSame(390, $quote->markupPct);
    }

    /**
     * fix-03 (мелкая находка 13): строка позиции и итог не расходятся. Раньше при копеечной
     * `unitPrice` строка печатала «0 💰» (округлённая сырая стоимость), пока итог включал
     * весь пол `min_markup_gold` — на одной покупаемой строке весь `total` теперь и есть
     * её видимая стоимость.
     */
    public function testLineTotalMatchesQuoteTotalWhenSinglePaidLine(): void
    {
        $svc = $this->service(
            ['enabled' => true, 'base_markup_pct' => 5, 'slope_markup_pct' => 0, 'min_markup_gold' => 3, 'profit_gate_enabled' => false],
            ['Вода' => ['id' => 1, 'buy_price' => 0.1]],
            backpack: ['Вода' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Вода' => 1]], 1);

        $this->assertSame(1, count($quote->lines));
        $this->assertSame((float) $quote->total, $quote->lines[0]['lineTotal']);
        $this->assertGreaterThan(0, (int) round($quote->lines[0]['lineTotal']));
    }

    public function testUnpurchasablePositionExcludedFromBaseButCountedInFull(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            [
                'Базальт'       => ['id' => 1, 'buy_price' => 2.0],
                'Связанный ресурс' => ['id' => 2, 'buy_price' => 5.0, 'is_tradeable' => 0],
            ],
            backpack: ['Базальт' => 0, 'Связанный ресурс' => 0]
        );

        $quote = $svc->quote(
            ['id' => 1, 'level' => 5, 'gold' => 1000],
            ['resources' => ['Базальт' => 1, 'Связанный ресурс' => 1]],
            1
        );

        $this->assertFalse($quote->available);
        $this->assertSame('not_tradeable', $quote->refusal);
        // base не видит связанный ресурс (2.0), full — видит (2.0 + 5.0 = 7.0).
        $this->assertEqualsWithDelta(2.0, $quote->baseCost, 0.001);
        $this->assertEqualsWithDelta(7.0, $quote->fullCost, 0.001);
    }

    public function testLevelGatedResourceBlocksWithConcreteReason(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Артефакт древних' => ['id' => 1, 'buy_price' => 10.0, 'level_required' => 50]],
            backpack: ['Артефакт древних' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Артефакт древних' => 1]], 1);

        $this->assertFalse($quote->available);
        $this->assertSame('level_required', $quote->refusal);
        $this->assertSame(0.0, $quote->baseCost);
    }

    public function testCraftedComponentBlocksWithNotOnSaleAndIsExcludedFromBase(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Мхи' => ['id' => 1, 'buy_price' => 1.0]],
            backpack: ['Мхи' => 10],
            craftedRows: ['metalFragments' => ['id' => 7, 'name_rus' => 'Металлические обломки']],
            craftedLog: [7 => ['quantity' => 0]]
        );

        $quote = $svc->quote(
            ['id' => 1, 'level' => 5, 'gold' => 1000],
            ['resources' => ['Мхи' => 2], 'crafted_items' => ['metalFragments' => 3]],
            1
        );

        $this->assertFalse($quote->available);
        $this->assertSame('not_on_sale', $quote->refusal);
        $componentLine = null;
        foreach ($quote->lines as $line) {
            if ($line['name'] === 'Металлические обломки') {
                $componentLine = $line;
            }
        }
        $this->assertNotNull($componentLine);
        $this->assertFalse($componentLine['buyable']);
        $this->assertSame('not_on_sale', $componentLine['blockReason']);
        // «Мхи» в достатке (10 >= 2) — не входит ни в base, ни в lines.
        $this->assertSame(0.0, $quote->baseCost);
    }

    public function testNoDivisionByZeroWhenFullCostIsZero(): void
    {
        // Ресурс существует, но цена покупки 0 (сезонное семя без buy_price) — full=0.
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Семя' => ['id' => 1, 'buy_price' => 0.0]],
            backpack: ['Семя' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Семя' => 5]], 1);

        $this->assertSame(0.0, $quote->share);
        $this->assertSame(0, $quote->total);
    }

    public function testNoDivisionByZeroAndAvailableWhenNothingIsMissing(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Базальт' => ['id' => 1, 'buy_price' => 2.0]],
            backpack: ['Базальт' => 100]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Базальт' => 1]], 1);

        $this->assertSame([], $quote->lines);
        $this->assertSame(0, $quote->total);
        $this->assertTrue($quote->available);
        $this->assertNull($quote->refusal);
    }

    public function testMinGoldRefusalWhenCharacterCannotAfford(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Базальт' => ['id' => 1, 'buy_price' => 1000.0]],
            backpack: ['Базальт' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 10], ['resources' => ['Базальт' => 1]], 1);

        $this->assertFalse($quote->available);
        $this->assertSame('min_gold', $quote->refusal);
    }

    /**
     * Общая заготовка для гейта рентабельности: не хватает 1 ед. Базальта по 1💰 →
     * `base=full=1`, `share=1`, `total=2` (та же арифметика, что во флагманском тесте
     * наценки выше). Персонаж на нейтральной карме, без Склада — `sellUnitPrice(price,
     * 100) === price` (см. `CraftTradeServiceTest::testSellDisplayEqualsWhatTransactionPays`),
     * поэтому выручка предсказуема: `price` ровно.
     *
     * @param array<string,mixed> $recipeExtra что добавить к recipe сверх `resources`
     * @param array<string,array{id:int,name_rus:string,price?:float}> $craftedRows
     */
    private function profitGateQuote(array $settings, array $recipeExtra, array $craftedRows = []): \App\Services\Craft\CraftShortfallQuote
    {
        $svc = $this->service(
            $settings,
            ['Базальт' => ['id' => 1, 'buy_price' => 1.0]],
            backpack: ['Базальт' => 0],
            craftedRows: $craftedRows
        );

        return $svc->quote(
            ['id' => 1, 'level' => 5, 'gold' => 1000000, 'trading_karma' => $this->neutralKarma()],
            ['resources' => ['Базальт' => 1]] + $recipeExtra,
            1
        );
    }

    public function testProfitGateOffByDefaultDoesNotBlockEvenWhenUnprofitable(): void
    {
        // total=2, «Топорик» продаётся за 1💰 — заведомо убыточная докупка, но гейт выключен.
        $quote = $this->profitGateQuote(
            self::DEFAULT_SETTINGS,
            ['item_name_eng' => 'CheapAxe'],
            ['CheapAxe' => ['id' => 9, 'name_rus' => 'Дешёвый топорик', 'price' => 1.0]]
        );

        $this->assertTrue($quote->available);
        $this->assertNull($quote->refusal);
    }

    /** Гейт способен СРАБОТАТЬ: включённая настройка + заведомо убыточный рецепт → отказ. */
    public function testProfitGateBlocksWhenEnabledAndRecipeIsUnprofitable(): void
    {
        $settings = self::DEFAULT_SETTINGS;
        $settings['profit_gate_enabled'] = true;

        // total=2 > выручка 1💰 за «Дешёвый топорик» — докупка дороже, чем даст продажа предмета.
        $quote = $this->profitGateQuote(
            $settings,
            ['item_name_eng' => 'CheapAxe'],
            ['CheapAxe' => ['id' => 9, 'name_rus' => 'Дешёвый топорик', 'price' => 1.0]]
        );

        $this->assertFalse($quote->available);
        $this->assertSame('profit_gate', $quote->refusal);
    }

    /** Гейт способен НЕ СРАБОТАТЬ: включённая настройка + заведомо доходный рецепт → доступно. */
    public function testProfitGateDoesNotBlockWhenEnabledAndRecipeIsProfitable(): void
    {
        $settings = self::DEFAULT_SETTINGS;
        $settings['profit_gate_enabled'] = true;

        // total=2 ≪ выручка 100💰 за «Дорогой топор» — докупка явно окупается сборкой и продажей.
        $quote = $this->profitGateQuote(
            $settings,
            ['item_name_eng' => 'ExpensiveAxe'],
            ['ExpensiveAxe' => ['id' => 10, 'name_rus' => 'Дорогой топор', 'price' => 100.0]]
        );

        $this->assertTrue($quote->available);
        $this->assertNull($quote->refusal);
    }

    /**
     * Гейт обязан вести себя ПРЕДСКАЗУЕМО, если цену предмета определить нельзя (рецепт не
     * несёт `item_name_eng`): fail-closed, а не молчаливый пропуск проверки — иначе включённый
     * гейт выглядел бы рабочим, не будучи им. fix-03 (крупная находка 8): причина отдельная от
     * `profit_gate` — сервис не вычислял невыгодность, ему неоткуда было взять цену.
     */
    public function testProfitGateFailsClosedWhenItemPriceCannotBeResolved(): void
    {
        $settings = self::DEFAULT_SETTINGS;
        $settings['profit_gate_enabled'] = true;

        // Рецепт без item_name_eng вовсе — цену взять неоткуда.
        $quote = $this->profitGateQuote($settings, []);

        $this->assertFalse($quote->available);
        $this->assertSame('price_unknown', $quote->refusal);
    }

    /**
     * fix-03 (крупная находка 8): реальный случай ~70 из 105 `item_name_eng` — рецепт НЕСЁТ имя,
     * но строки в `crafted_items` для него нет (output_type weapon/outfit/resource продаются
     * иначе). Раньше это молча превращалось в `profit_gate` — «цена превысит выручку», хотя
     * выручку никто не считал. Теперь — честная отдельная причина, а не выдуманная невыгодность.
     */
    public function testProfitGateReturnsPriceUnknownWhenRecipeNotInCraftedItemsTable(): void
    {
        $settings = self::DEFAULT_SETTINGS;
        $settings['profit_gate_enabled'] = true;

        // item_name_eng есть, но craftedRows этого имени не знает — как у weapon/outfit-рецептов.
        $quote = $this->profitGateQuote($settings, ['item_name_eng' => 'SomeWeaponNotInCraftedItems']);

        $this->assertFalse($quote->available);
        $this->assertSame('price_unknown', $quote->refusal);
    }

    /**
     * fix-11 (ремонт повторного ревью): раньше `unitPrice` в строке оставался сырым
     * (без наценки), пока `distributeTotalAcrossLines()` уже переписывал `lineTotal` на
     * долю от `total` (с наценкой) — экран печатал «6 × 1.50 = 9 💰», а рядом стоял
     * итог 11. Тут ровно тот сценарий из истории ремонта: gap=6, raw price=1.5 (base=9),
     * `min_markup_gold=2` даёт `total=11`. Печатаемая цена за штуку обязана быть уже
     * пересчитана так, чтобы `unitPrice × gap === lineTotal`.
     */
    public function testLineUnitPriceMatchesLineTotalAfterMarkupDistribution(): void
    {
        $svc = $this->service(
            ['enabled' => true, 'base_markup_pct' => 0, 'slope_markup_pct' => 0, 'min_markup_gold' => 2, 'profit_gate_enabled' => false],
            ['Древесина' => ['id' => 7, 'buy_price' => 1.5]],
            backpack: ['Древесина' => 4]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Древесина' => 10]], 1);

        // base=9 (6×1.5), total = base + min_markup_gold = 11.
        $this->assertSame(11, $quote->total);
        $this->assertSame(1, count($quote->lines));
        $line = $quote->lines[0];
        $this->assertSame(6, $line['gap']);
        $this->assertSame(11.0, $line['lineTotal']);
        // Печатаемое произведение «цена за штуку × количество» обязано сходиться со
        // строкой — а не с сырой ценой 1.5, которая при 6 шт. дала бы 9, а не 11.
        $this->assertEqualsWithDelta($line['lineTotal'], $line['unitPrice'] * $line['gap'], 0.0001);
        $this->assertNotEqualsWithDelta(1.5, $line['unitPrice'], 0.0001, 'сырая цена без наценки не должна остаться в строке рядом с итогом, куда наценка уже вшита');
    }

    public function testQuantityMultipliesPerUnitRequirement(): void
    {
        $svc = $this->service(
            self::DEFAULT_SETTINGS,
            ['Базальт' => ['id' => 1, 'buy_price' => 1.0]],
            backpack: ['Базальт' => 0]
        );

        $quote = $svc->quote(['id' => 1, 'level' => 5, 'gold' => 1000], ['resources' => ['Базальт' => 2]], 3);

        $this->assertSame(6, $quote->lines[0]['need']);
        $this->assertSame(6, $quote->lines[0]['gap']);
    }
}
