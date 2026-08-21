<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Entities\CharacterEntity;
use App\Entities\ResourceEntity;
use App\Services\Craft\CraftShortageService;
use App\Services\Craft\CraftShortfallBuyService;
use App\Services\Craft\CraftShortfallQuote;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-158 — экран «чего не хватает».
 *
 * Прод за 60 дней: 144 отказа крафта, из них 137 — нехватка сырья и 127 — попытка
 * собрать ОДНУ штуку. Раньше игрок в ответ получал «Недостаточно ресурсов для крафта
 * N шт.» без списка и кнопок, хотя точный список сервер уже считал.
 *
 * @internal
 */
final class CraftShortageServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string}> $resources
     * @param array<int,string> $biomes
     * @param array<int,string> $hints
     */
    private function service(array $resources, array $biomes = [], array $hints = []): CraftShortageService
    {
        return new class ($resources, $biomes, $hints) extends CraftShortageService {
            /**
             * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string}> $resources
             * @param array<int,string> $biomes
             * @param array<int,string> $hints
             */
            public function __construct(
                private array $resources,
                private array $biomes,
                private array $hints
            ) {
                parent::__construct();
            }

            protected function findResource(string $name): ?ResourceEntity
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name]);
            }

            protected function loadBiomeNames(): array
            {
                return $this->biomes;
            }

            protected function compassHints(array|CharacterEntity $character): array
            {
                return $this->hints;
            }
        };
    }

    /** @return array<string,array{need:int,have:int,name:string}> */
    private function missing(string $name, int $need, int $have): array
    {
        return [$name => ['need' => $need, 'have' => $have, 'name' => $name]];
    }

    /**
     * `CraftShortfallBuyService::quote()` — единственный источник чисел блока
     * докупки (story `craft-shortfall-buy-06`). Тесты этого файла не пересчитывают
     * его формулу — они подставляют канонический DTO и проверяют, что экран
     * ничего не досчитывает сам и честно показывает lock-состояния/отказы.
     */
    private function stubShortfallBuy(CraftShortfallQuote $quote): CraftShortfallBuyService
    {
        return new class ($quote) extends CraftShortfallBuyService {
            public function __construct(private CraftShortfallQuote $stubbedQuote)
            {
            }

            public function quote(CharacterEntity|array $character, array $recipe, int $quantity): CraftShortfallQuote
            {
                return $this->stubbedQuote;
            }
        };
    }

    /**
     * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string,level_required?:int}> $resources
     */
    private function serviceWithShortfall(CraftShortfallQuote $quote, array $resources = []): CraftShortageService
    {
        $shortfallBuy = $this->stubShortfallBuy($quote);

        return new class ($resources, $shortfallBuy) extends CraftShortageService {
            /**
             * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string,level_required?:int}> $resources
             */
            public function __construct(private array $resources, CraftShortfallBuyService $shortfallBuy)
            {
                parent::__construct(shortfallBuy: $shortfallBuy);
            }

            protected function findResource(string $name): ?ResourceEntity
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name]);
            }
        };
    }

    public function testNamesExactlyWhatIsMissing(): void
    {
        $svc = $this->service(['Древесина' => ['id' => 7, 'is_tradeable' => 1, 'buy_price' => 1.12, 'biome_id' => '1']]);

        $screen = $svc->describe([], $this->missing('Древесина', 240, 40), [], 1);

        $this->assertStringContainsString('Древесина', $screen['text']);
        $this->assertStringContainsString('нужно 240, есть 40', $screen['text']);
    }

    /** Главная ценность экрана: не «нет ресурсов», а «вот где это добывается». */
    public function testTellsWhereToGatherWithBiomeAndCompass(): void
    {
        $svc = $this->service(
            ['Базальт' => ['id' => 12, 'is_tradeable' => 0, 'buy_price' => 0.0, 'biome_id' => '5,9']],
            [5 => 'Вулканы', 9 => 'Пустыня'],
            [5 => 'редкий · северо-восток, далеко']
        );

        $text = $svc->describe([], $this->missing('Базальт', 20, 0), [], 1)['text'];

        $this->assertStringContainsString('Вулканы', $text);
        $this->assertStringContainsString('северо-восток', $text);
    }

    public function testShowsBuyCostAndDeepLinkForTradeableResource(): void
    {
        $svc = $this->service(['Смола деревьев' => ['id' => 31, 'is_tradeable' => 1, 'buy_price' => 2.5, 'biome_id' => '1']]);

        $screen = $svc->describe([], $this->missing('Смола деревьев', 40, 10), [], 1);

        // Докупить нужно 30 по 2.5 → 75.
        $this->assertStringContainsString('докупить 30', $screen['text']);
        $this->assertStringContainsString('75', $screen['text']);
        // Дефицит-ссылка несёт и ресурс, и недостачу: 30 — это то, что кнопка купит одним тапом.
        $this->assertStringContainsString('buy_need_31_30', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Нетоварное сырьё: честно говорим, что купить нельзя, и не рисуем кнопку. */
    public function testSaysWhenResourceCannotBeBought(): void
    {
        $svc = $this->service(['Обсидиан' => ['id' => 44, 'is_tradeable' => 0, 'buy_price' => 0.0, 'biome_id' => '5']]);

        $screen = $svc->describe([], $this->missing('Обсидиан', 5, 0), [], 1);

        $this->assertStringContainsString('не купить', $screen['text']);
        $this->assertStringNotContainsString('buy_need_44', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Порядок рядов — сообщение о ценностях: добыть раньше, чем купить. Обратный
     * порядок сообщал бы новичку, что платить — нормальный первый ход.
     */
    public function testGatherRowComesBeforeBuyRow(): void
    {
        $svc = $this->service(['Древесина' => ['id' => 7, 'is_tradeable' => 1, 'buy_price' => 1.12, 'biome_id' => '1']]);

        $rows = $svc->describe([], $this->missing('Древесина', 100, 0), [], 1)['keyboard']['inline_keyboard'];

        $gatherRow = null;
        $buyRow    = null;
        foreach ($rows as $i => $row) {
            foreach ($row as $btn) {
                if (($btn['callback_data'] ?? '') === 'gather') {
                    $gatherRow = $i;
                }
                if (str_starts_with($btn['callback_data'] ?? '', 'buy_need_')) {
                    $buyRow = $i;
                }
            }
        }

        $this->assertNotNull($gatherRow, 'кнопка добычи обязана быть всегда');
        $this->assertNotNull($buyRow);
        $this->assertLessThan($buyRow, $gatherRow);
    }

    /** Экран отказа не должен быть тупиком — выход есть даже без единого ресурса в базе. */
    public function testAlwaysOffersAWayForward(): void
    {
        $rows = $this->service([])->describe([], $this->missing('Неизвестный', 1, 0), [], 1)['keyboard']['inline_keyboard'];

        $callbacks = [];
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                $callbacks[] = $btn['callback_data'] ?? '';
            }
        }

        $this->assertContains('gather', $callbacks);
        $this->assertContains('inventory', $callbacks);
    }

    public function testComponentIsMarkedAsCraftable(): void
    {
        $svc  = $this->service([]);
        $text = $svc->describe(
            [],
            [],
            ['metalFragments' => ['need' => 20, 'have' => 3, 'name' => 'Металл фрагменты']],
            1
        )['text'];

        $this->assertStringContainsString('Металл фрагменты', $text);
        $this->assertStringContainsString('верстак', $text);
    }

    /** Легаси Markdown не экранирует служебные символы — звёздочки обязаны быть парными. */
    public function testMarkdownStaysBalancedEvenWithDirtyNames(): void
    {
        $svc = $this->service(['Ржавый *лом*' => ['id' => 3, 'is_tradeable' => 1, 'buy_price' => 1.0, 'biome_id' => '1']]);

        $text = $svc->describe(
            [],
            $this->missing('Ржавый *лом*', 5, 1),
            [],
            2,
            ['item_name_rus' => 'Щит_прототип']
        )['text'];

        $this->assertSame(0, substr_count($text, '*') % 2, 'непарная * рвёт разметку и Telegram молча не доставит сообщение');
        $this->assertSame(0, substr_count($text, '_') % 2);
    }

    /** Длинный список не раздувает сообщение: подробно — первые несколько, дальше счётчик. */
    public function testLongListIsTruncatedHonestly(): void
    {
        $missing = [];
        for ($i = 1; $i <= 9; $i++) {
            $missing['Ресурс ' . $i] = ['need' => 10, 'have' => 0, 'name' => 'Ресурс ' . $i];
        }

        $text = $this->service([])->describe([], $missing, [], 1)['text'];

        $this->assertStringContainsString('и ещё 3 позиц', $text);
    }

    /**
     * Story `craft-shortfall-buy-08`: числа блока «Докупить у торговца» приходят
     * из `quote()` один в один — наценка, итог и остаток золота не пересчитываются.
     */
    public function testShortfallBuyBlockShowsPositionsAndSummaryWhenAvailable(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 7, 'name' => 'Древесина', 'need' => 10, 'have' => 4, 'gap' => 6, 'unitPrice' => 1.5, 'lineTotal' => 9.0, 'buyable' => true, 'blockReason' => null],
            ],
            baseCost: 9.0,
            fullCost: 30.0,
            share: 0.3,
            markupPct: 23,
            total: 12,
            goldAfter: 88,
            available: true,
            refusal: null
        );

        $svc  = $this->serviceWithShortfall($quote);
        $text = $svc->describe(['level' => 4, 'gold' => 100], [], [], 1, ['resources' => ['Древесина' => 10]])['text'];

        $this->assertStringContainsString('Докупить у торговца', $text);
        $this->assertStringContainsString('Древесина', $text);
        $this->assertStringContainsString('наценка торговца за срочность', mb_strtolower($text));
        $this->assertStringContainsString('+23%', $text);
        $this->assertStringContainsString('12', $text);
        $this->assertStringContainsString('88', $text);
        // Существующая строка про добычу обязана остаться правдой рядом с новым блоком.
        $this->assertStringContainsString('Добыть почти всегда дешевле, чем купить', $text);
    }

    public function testWordOptIsNeverUsed(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 7, 'name' => 'Древесина', 'need' => 10, 'have' => 4, 'gap' => 6, 'unitPrice' => 1.5, 'lineTotal' => 9.0, 'buyable' => true, 'blockReason' => null],
            ],
            baseCost: 9.0,
            fullCost: 30.0,
            share: 0.3,
            markupPct: 23,
            total: 12,
            goldAfter: 88,
            available: true,
            refusal: null
        );

        $svc  = $this->serviceWithShortfall($quote);
        $text = $svc->describe(['level' => 4, 'gold' => 100], [], [], 1, ['resources' => ['Древесина' => 10]])['text'];

        $this->assertStringNotContainsStringIgnoringCase('опт', $text);
    }

    /** Непокупаемая позиция: причина и уровень видны ДО тапа, а не после отказа. */
    public function testShortfallBuyBlockShowsLevelRequiredLockWithConcreteNumber(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 12, 'name' => 'Базальт', 'need' => 5, 'have' => 0, 'gap' => 5, 'unitPrice' => 0.0, 'lineTotal' => 0.0, 'buyable' => false, 'blockReason' => CraftShortfallBuyService::REASON_LEVEL_REQUIRED],
            ],
            baseCost: 0.0,
            fullCost: 10.0,
            share: 0.0,
            markupPct: 0,
            total: 0,
            goldAfter: 100,
            available: false,
            refusal: CraftShortfallBuyService::REASON_LEVEL_REQUIRED
        );

        $svc  = $this->serviceWithShortfall($quote, ['Базальт' => ['id' => 12, 'is_tradeable' => 0, 'buy_price' => 0.0, 'biome_id' => '5', 'level_required' => 8]]);
        $text = $svc->describe(['level' => 4], [], [], 1, ['resources' => ['Базальт' => 5]])['text'];

        $this->assertStringContainsString('с 8 уровня', $text);
        $this->assertStringContainsString('у тебя 4', $text);
        $this->assertStringNotContainsString('недоступно.', $text);
    }

    public function testShortfallBuyBlockShowsNotTradeableLock(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 44, 'name' => 'Обсидиан', 'need' => 5, 'have' => 0, 'gap' => 5, 'unitPrice' => 0.0, 'lineTotal' => 0.0, 'buyable' => false, 'blockReason' => CraftShortfallBuyService::REASON_NOT_TRADEABLE],
            ],
            baseCost: 0.0,
            fullCost: 5.0,
            share: 0.0,
            markupPct: 0,
            total: 0,
            goldAfter: 100,
            available: false,
            refusal: CraftShortfallBuyService::REASON_NOT_TRADEABLE
        );

        $text = $this->serviceWithShortfall($quote)->describe([], [], [], 1, ['resources' => ['Обсидиан' => 5]])['text'];

        $this->assertStringContainsString('не продаётся', $text);
        $this->assertStringContainsString('добыть', $text);
    }

    public function testShortfallBuyBlockShowsNotOnSaleLockForComponent(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 0, 'name' => 'Металл фрагменты', 'need' => 20, 'have' => 3, 'gap' => 17, 'unitPrice' => 0.0, 'lineTotal' => 0.0, 'buyable' => false, 'blockReason' => CraftShortfallBuyService::REASON_NOT_ON_SALE],
            ],
            baseCost: 0.0,
            fullCost: 0.0,
            share: 0.0,
            markupPct: 0,
            total: 0,
            goldAfter: 100,
            available: false,
            refusal: CraftShortfallBuyService::REASON_NOT_ON_SALE
        );

        $text = $this->serviceWithShortfall($quote)->describe([], [], [], 1, ['crafted_items' => ['metalFragments' => 20]])['text'];

        $this->assertStringContainsString('крафтовый компонент', $text);
        $this->assertStringContainsString('верстак', $text);
    }

    public function testShortfallBuyBlockExplainsMinGoldRefusalWithAmountAndPath(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 7, 'name' => 'Древесина', 'need' => 10, 'have' => 0, 'gap' => 10, 'unitPrice' => 5.0, 'lineTotal' => 50.0, 'buyable' => true, 'blockReason' => null],
            ],
            baseCost: 50.0,
            fullCost: 50.0,
            share: 1.0,
            markupPct: 15,
            total: 58,
            goldAfter: -15,
            available: false,
            refusal: CraftShortfallBuyService::REASON_MIN_GOLD
        );

        $text = $this->serviceWithShortfall($quote)->describe([], [], [], 1, ['resources' => ['Древесина' => 10]])['text'];

        $this->assertStringContainsString('Не хватает', $text);
        $this->assertStringContainsString('15', $text);
        $this->assertStringContainsString('заработай', $text);
    }

    public function testShortfallBuyBlockExplainsProfitGateRefusal(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [
                ['resourceId' => 7, 'name' => 'Древесина', 'need' => 10, 'have' => 0, 'gap' => 10, 'unitPrice' => 5.0, 'lineTotal' => 50.0, 'buyable' => true, 'blockReason' => null],
            ],
            baseCost: 50.0,
            fullCost: 50.0,
            share: 1.0,
            markupPct: 15,
            total: 58,
            goldAfter: 42,
            available: false,
            refusal: CraftShortfallBuyService::REASON_PROFIT_GATE
        );

        $text = $this->serviceWithShortfall($quote)->describe([], [], [], 1, ['resources' => ['Древесина' => 10]])['text'];

        $this->assertStringContainsString('невыгодна', $text);
        $this->assertStringContainsString('добыть сырьё самому', $text);
    }

    /** Killswitch выключен (или докупать нечего) — блок не показывается, экран работает как раньше. */
    public function testShortfallBuyBlockHiddenWhenNothingToOffer(): void
    {
        $quote = new CraftShortfallQuote(
            lines: [],
            baseCost: 0.0,
            fullCost: 0.0,
            share: 0.0,
            markupPct: 0,
            total: 0,
            goldAfter: 100,
            available: false,
            refusal: CraftShortfallBuyService::REASON_KILLSWITCH_OFF
        );

        $text = $this->serviceWithShortfall($quote)->describe([], $this->missing('Древесина', 10, 0), [], 1, ['resources' => ['Древесина' => 10]])['text'];

        $this->assertStringNotContainsString('Докупить у торговца', $text);
    }

    /** Без recipe (как зовут остальные тесты этого файла) блок не появляется — старое поведение цело. */
    public function testShortfallBuyBlockAbsentWithoutRecipe(): void
    {
        $text = $this->service(['Древесина' => ['id' => 7, 'is_tradeable' => 1, 'buy_price' => 1.12, 'biome_id' => '1']])
            ->describe([], $this->missing('Древесина', 240, 40), [], 1)['text'];

        $this->assertStringNotContainsString('Докупить у торговца', $text);
    }

    /** Длинный список позиций схлопывается счётчиком, а не молча обрезает caption. */
    public function testShortfallBuyBlockLongListCollapsesAndStaysWithinCaptionLimit(): void
    {
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = [
                'resourceId'  => $i,
                'name'        => 'Ресурс ' . $i,
                'need'        => 5,
                'have'        => 2,
                'gap'         => 3,
                'unitPrice'   => 1.5,
                'lineTotal'   => 4.5,
                'buyable'     => true,
                'blockReason' => null,
            ];
        }

        $quote = new CraftShortfallQuote(
            lines: $lines,
            baseCost: 135.0,
            fullCost: 150.0,
            share: 0.9,
            markupPct: 45,
            total: 196,
            goldAfter: 4,
            available: true,
            refusal: null
        );

        $recipe = ['resources' => array_combine(array_map(static fn (int $i) => 'Ресурс ' . $i, range(1, 30)), array_fill(0, 30, 5))];
        $text   = $this->serviceWithShortfall($quote)->describe([], [], [], 1, $recipe)['text'];

        $this->assertLessThanOrEqual(1024, mb_strlen($text), 'Telegram молча отвергает caption длиннее 1024 символов');
        $this->assertStringContainsString('и ещё 24 позиц', $text);
    }
}
