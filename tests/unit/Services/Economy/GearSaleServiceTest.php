<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\GameSettingsModel;
use App\Services\Economy\GearSaleService;
use App\Services\Economy\TradePricingService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-165 — продажа экипировки торговцу.
 *
 * Два предмета проверки:
 *  1. **Цена.** Доля от базовой цены применяется ДО торгового конвейера, поэтому выкуп
 *     экипировки не может стать промышленным источником золота. Без доли торговец при
 *     нейтральной карме платит РОВНО базовую цену — а у экипировки это ценник новой
 *     вещи (до 120 000), и «скрафтил → сдал» печатало бы деньги.
 *  2. **Фильтры.** Надетое и соулбаунд-трофеи (ADR-137) не продаются, причём и в списке,
 *     и при клике по конкретной строке: расхождение между ними означало бы, что прямой
 *     callback обходит правило.
 *
 * @internal
 */
final class GearSaleServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /**
     * Сервис на подменённых настройках и БЕЗ соединения с БД: арифметика цены и разбор
     * callback'ов от базы не зависят (соединение в сервисе ленивое).
     *
     * @param array<string,float|bool> $overrides
     */
    private function service(array $overrides = []): GearSaleService
    {
        // GameSettingsService кэширует значение по ключу, поэтому два сервиса с РАЗНЫМИ
        // настройками внутри одного теста читали бы одно и то же значение — первое.
        service('cache')->clean();

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

        return new GearSaleService(new GameSettingsService($settingsModel), null);
    }

    public function testEnabledByDefault(): void
    {
        $this->assertTrue($this->service()->isEnabled());
        $this->assertFalse($this->service([GearSaleService::KEY_ENABLED => false])->isEnabled());
    }

    public function testDefaultPricePctIsQuarter(): void
    {
        $this->assertEqualsWithDelta(0.25, $this->service()->pricePct(), 0.0001);
    }

    /** Доля admin-tunable (ADR-024), но за пределы [0,1] выйти не может даже сырым UPDATE. */
    public function testPricePctIsClampedToUnitInterval(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->service([GearSaleService::KEY_PRICE_PCT => 7.5])->pricePct(), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->service([GearSaleService::KEY_PRICE_PCT => -3.0])->pricePct(), 0.0001);
    }

    /**
     * Регрессия главного риска: при нейтральной карме конвейер платит базовую цену
     * целиком. «Гидра» Плазмопушка стоит 120 000 — без доли одна сделка выдавала бы
     * больше двух суточных лимитов выкупа.
     */
    public function testUnitPriceIsFractionOfBasePriceAtNeutralKarma(): void
    {
        $pricing = new TradePricingService();
        $neutral = (float) (new \Config\GameBalance())->startingTradingKarma;

        $full = $pricing->sellUnitPrice(120000.0, $neutral);
        $gear = $this->service()->unitPrice(120000.0, $neutral, 1.0, $pricing);

        // Именно этот случай и есть риск: при нейтральной карме конвейер отдаёт
        // базовую цену целиком, без всякой скидки.
        $this->assertEqualsWithDelta(120000.0, $full, 0.001);

        $this->assertEqualsWithDelta($full * 0.25, $gear, 0.001);
        $this->assertLessThan($full, $gear, 'экипировка обязана выкупаться дешевле, чем «как новая»');
    }

    /** Цена линейна по доле — админ меняет ровно то, что обещает rationale. */
    public function testUnitPriceFollowsTunablePct(): void
    {
        $cheap = $this->service([GearSaleService::KEY_PRICE_PCT => 0.10])->unitPrice(1000.0, 0.0);
        $rich  = $this->service([GearSaleService::KEY_PRICE_PCT => 0.50])->unitPrice(1000.0, 0.0);

        $this->assertEqualsWithDelta($cheap * 5.0, $rich, 0.001);
    }

    public function testNegativeBasePriceNeverPays(): void
    {
        $this->assertSame(0.0, $this->service()->unitPrice(-500.0, 0.0));
    }

    public function testCategoryAndCodeRoundTrip(): void
    {
        $svc = $this->service();

        $this->assertSame(GearSaleService::KIND_WEAPON, $svc->kindForCategory(GearSaleService::CATEGORY_WEAPON));
        $this->assertSame(GearSaleService::KIND_OUTFIT, $svc->kindForCategory(GearSaleService::CATEGORY_ARMOR));
        // Обычные крафтовые категории обязаны остаться чужими — иначе экран продажи
        // крафта уйдёт читать таблицы экипировки.
        $this->assertNull($svc->kindForCategory('drug'));
        $this->assertNull($svc->kindForCategory('weapon'));
        $this->assertNull($svc->kindForCategory(''));

        $this->assertSame(GearSaleService::KIND_WEAPON, $svc->kindForCode($svc->codeForKind(GearSaleService::KIND_WEAPON)));
        $this->assertSame(GearSaleService::KIND_OUTFIT, $svc->kindForCode($svc->codeForKind(GearSaleService::KIND_OUTFIT)));
        $this->assertNull($svc->kindForCode('x'));
    }

    public function testTablesForKind(): void
    {
        $svc = $this->service();

        $this->assertSame(['characters_weapons', 'weapon_id', 'weapons'], $svc->tablesFor(GearSaleService::KIND_WEAPON));
        $this->assertSame(['characters_outfits', 'outfit_id', 'outfits'], $svc->tablesFor(GearSaleService::KIND_OUTFIT));
    }

    /**
     * Инвариант ADR-137, записанный в миграции соулбаунд-колонок прямым текстом:
     * «если когда-либо появится sell/loss-путь для экипировки — он ОБЯЗАН уважать
     * `is_soulbound`». Плюс надетое.
     *
     * Проверяем сканом исходника, а не БД: тестовая база частичная (в ней нет мира и
     * части игровых таблиц), а потерять один из двух фильтров можно ровно в одном из
     * двух запросов — список и точечное чтение обязаны фильтровать ОДИНАКОВО.
     */
    public function testBothQueriesGuardEquippedAndSoulbound(): void
    {
        $source = file_get_contents(APPPATH . 'Services/Economy/GearSaleService.php');
        $this->assertIsString($source);

        $this->assertSame(
            2,
            substr_count($source, 'COALESCE(g.equipped, 0) = 0'),
            'фильтр надетого обязан стоять и в списке, и в точечном чтении'
        );
        $this->assertSame(
            2,
            substr_count($source, 'COALESCE(g.is_soulbound, 0) = 0'),
            'фильтр соулбаунда обязан стоять и в списке, и в точечном чтении (ADR-137)'
        );
        $this->assertSame(
            2,
            substr_count($source, 'g.quantity > 0'),
            'пустой стек не должен попадать ни в список, ни в сделку'
        );
    }

    /**
     * Сделка обязана попадать в `transactions` — там же считается суточный лимит выкупа
     * (ADR-157). Отдельный журнал означал бы канал продаж мимо потолка.
     */
    public function testConfirmActionWritesToTransactionsAndRespectsDailyCap(): void
    {
        $source = file_get_contents(
            APPPATH . 'Controllers/Telegram/Commands/Actions/Sell/SellGearConfirmAction.php'
        );
        $this->assertIsString($source);

        $this->assertStringContainsString("table('transactions')", $source);
        $this->assertStringContainsString('VendorDailyLimitService', $source);
        $this->assertStringContainsString('FOR UPDATE', $source);
        // В витрину «Купить крафт» экипировка не попадает: выкупа обратно у неё нет.
        $this->assertStringNotContainsString('SalesModel', $source);
    }
}
