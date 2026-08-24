<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components\WoodMaterialsCraft1Action;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * chat-requests-batch-04 — готовка на костре: выбор количества, как в обычном
 * крафте, вместо жёсткого `genericCraft_<Key>_1`.
 *
 * До фикса кнопка блюда сразу слала `genericCraft_<Key>_1`, а `handle()`
 * игнорировал всё, что шло после `cook`/`cookPreserves` в callback_data —
 * количество выбрать было нельзя. Тесты бьют в это напрямую через новые
 * чистые статические помощники (`parseCallback`/`dishStepCallback`/
 * `quantityButtons`) — до фикса ни один из них не существовал (fatal error =
 * красный тест), после — существуют и содержат реальную логику разбора и
 * упаковки кнопок.
 *
 * Полный end-to-end `handle()` (реальный CallbackQuery → MediaSender →
 * Telegram) здесь НЕ гоняется: экран фото-based (`Request::encodeFile` тянет
 * файл через `base_url()`), а `phpunit.xml.dist` намеренно ставит
 * `app.baseURL=http://example.com/` для тестов — попытка реально сходить в
 * сеть за картинкой. По этой же причине единственный найденный в репозитории
 * прецедент «реальный CallbackQuery + реальный handle()» (VehicleCraftWiringTest)
 * бьёт по text-only экрану (`CraftedResourcesAction`), не photo-экрану.
 * Рендер caption/кнопок в живом Telegram — по правилу `telegram-ux.md`,
 * Tier-3 smoke (PHPUnit его не ловит).
 *
 * @internal
 */
final class CampfireQuantityTest extends CIUnitTestCase
{
    // ── 1) Ступени количества — ТЕ ЖЕ, что у обычного крафта ──

    /**
     * Не «свой список из ТЗ» (1/5/50), а буквально тот же набор, что уже
     * показывает обычный крафт (WoodMaterialsCraft1Action и десятки соседей) —
     * два экрана крафта не должны вести себя по-разному.
     */
    public function testQuantityStepsMatchRegularCraftPattern(): void
    {
        $refl = new ReflectionClass(WoodMaterialsCraft1Action::class);
        $prop = $refl->getProperty('craftQuantities');
        $prop->setAccessible(true);
        /** @var list<int> $regularCraftSteps */
        $regularCraftSteps = $prop->getValue($refl->newInstanceWithoutConstructor());

        $this->assertSame(
            $regularCraftSteps,
            CampfireCookingSelect::QUANTITY_STEPS,
            'ступени количества готовки обязаны совпадать с обычным крафтом',
        );
    }

    // ── 2) Кнопка блюда ведёт на шаг количества, а не сразу в старт крафта ──

    /**
     * До фикса кнопка блюда буквально слала `genericCraft_MushroomSoup_1` —
     * крафт стартовал на 1 штуку сразу по клику, выбрать количество было
     * нельзя. `dishStepCallback()` — новый метод, до фикса не существовал.
     */
    public function testDishButtonLeadsToQuantityStepNotDirectlyToCraft(): void
    {
        $hot      = CampfireCookingSelect::dishStepCallback('MushroomSoup', false);
        $preserve = CampfireCookingSelect::dishStepCallback('StewPreserve', true);

        $this->assertSame('cook_qty_MushroomSoup', $hot);
        $this->assertSame('cookPreserves_qty_StewPreserve', $preserve);
        $this->assertNotSame('genericCraft_MushroomSoup_1', $hot);
    }

    // ── 3) Роутинг: `handle()` резолвит шаг количества по первому сегменту ──

    /**
     * Роутинг `CallbackqueryCommand` берёт ПЕРВЫЙ сегмент callback_data
     * (`explode('_', $data)[0]`) и резолвит его через `Config\CallbackRoutes`
     * (уже зарегистрированные exact-роуты `cook`/`cookPreserves`). Поэтому
     * `cook_qty_<Key>` обязан резолвиться ровно в тот же первый сегмент, что
     * список блюд — иначе новый шаг молча не роутится (протухшая кнопка).
     */
    public function testQuantityStepCallbackKeepsRoutableFirstSegment(): void
    {
        $routes = config('CallbackRoutes');

        $hotFirstSegment       = explode('_', CampfireCookingSelect::dishStepCallback('MushroomSoup', false))[0];
        $preserveFirstSegment  = explode('_', CampfireCookingSelect::dishStepCallback('StewPreserve', true))[0];

        $this->assertArrayHasKey('cook', $routes->exactRoutes);
        $this->assertArrayHasKey(CampfireCookingSelect::CB_PRESERVES, $routes->exactRoutes);
        $this->assertSame('cook', $hotFirstSegment);
        $this->assertSame(CampfireCookingSelect::CB_PRESERVES, $preserveFirstSegment);
        $this->assertSame(CampfireCookingSelect::class, $routes->resolve($hotFirstSegment));
        $this->assertSame(CampfireCookingSelect::class, $routes->resolve($preserveFirstSegment));
    }

    /** `parseCallback()` — обратная функция: список блюд vs шаг количества. */
    public function testParseCallbackDistinguishesListFromQuantityStep(): void
    {
        $this->assertSame(
            ['preserves' => false, 'qtyKey' => null],
            CampfireCookingSelect::parseCallback('cook'),
        );
        $this->assertSame(
            ['preserves' => true, 'qtyKey' => null],
            CampfireCookingSelect::parseCallback(CampfireCookingSelect::CB_PRESERVES),
        );
        $this->assertSame(
            ['preserves' => false, 'qtyKey' => 'MushroomSoup'],
            CampfireCookingSelect::parseCallback('cook_qty_MushroomSoup'),
        );
        $this->assertSame(
            ['preserves' => true, 'qtyKey' => 'StewPreserve'],
            CampfireCookingSelect::parseCallback('cookPreserves_qty_StewPreserve'),
        );
    }

    // ── 4) Шаг количества предлагает ровно набор QUANTITY_STEPS, без одиночек ──

    public function testQuantityStepOffersAllStandardSteps(): void
    {
        $buttons   = CampfireCookingSelect::quantityButtons('MushroomSoup');
        $callbacks = array_column($buttons, 'callback_data');

        foreach (CampfireCookingSelect::QUANTITY_STEPS as $qty) {
            $this->assertContains(
                "genericCraft_MushroomSoup_{$qty}",
                $callbacks,
                "не хватает кнопки на {$qty} шт.",
            );
        }
        $this->assertCount(count(CampfireCookingSelect::QUANTITY_STEPS), $buttons);
    }

    /**
     * Ноль кнопок-одиночек в ряду (правило проекта) — мерим ИТОГОВЫЕ ряды
     * `quantityStepRows()`, ровно то, что `handleQuantityStep()` кладёт в
     * `reply_markup` и что уходит в Telegram, а не промежуточный список
     * кнопок до упаковки (до фикса `ButtonPacker::pack($buttons)` без «Своё
     * число» проходил зелёным, а реальная клавиатура несла отдельный
     * хвостовой ряд из одной кнопки — этот тест его не видел).
     */
    public function testQuantityStepRowsHaveNoLoneButtonWithAllStepsAvailable(): void
    {
        $rows = CampfireCookingSelect::quantityStepRows('MushroomSoup', false, CampfireCookingSelect::QUANTITY_STEPS);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(1, count($row), 'ряд с единственной кнопкой запрещён');
        }

        // «Своё число» реально попала в клавиатуру (не потерялась при упаковке).
        $flat = array_merge(...$rows);
        $this->assertContains(
            CampfireCookingSelect::customQuantityCallback('MushroomSoup', false),
            array_column($flat, 'callback_data'),
        );
    }

    /**
     * Ветка нехватки сырья (`$steps = []`) — ДО фикса именно тут получались
     * ДВА одиночных ряда подряд: `[🛒 Чего не хватает?]` и `[📝 Своё число]`.
     * `fallbackButton()` подставляет одну кнопку, «Своё число» едет в тот же
     * пул перед упаковкой — обязаны слиться в один ряд из двух.
     */
    public function testQuantityStepRowsHaveNoLoneButtonOnShortage(): void
    {
        $rows = CampfireCookingSelect::quantityStepRows('MushroomSoup', false, []);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(1, count($row), 'ряд с единственной кнопкой запрещён (в т.ч. в ветке нехватки сырья)');
        }

        $flat      = array_merge(...$rows);
        $callbacks = array_column($flat, 'callback_data');
        $this->assertContains(CampfireCookingSelect::customQuantityCallback('MushroomSoup', false), $callbacks);
    }

    /** Умножение (ресурсы/золото/время) — работа `GenericCraftActionStart`, не дублируем. */
    public function testQuantityButtonsReuseGenericCraftMechanism(): void
    {
        foreach (CampfireCookingSelect::quantityButtons('StewPreserve') as $button) {
            $this->assertMatchesRegularExpression(
                '/^genericCraft_StewPreserve_\d+$/',
                $button['callback_data'],
                'кнопка количества обязана вести в GenericCraftActionStart (genericCraft_<Key>_<qty>)',
            );
        }
    }

    // ── 5) Подпись самодостаточна (media-off) ──

    public function testQuantityStepCaptionIsSelfContained(): void
    {
        $text = CampfireCookingSelect::renderQuantityText(
            false,
            '🍄',
            'Грибная похлёбка',
            'Грибы×4, Вода×2',
            10,
            15,
        );

        $this->assertStringContainsString('Грибная похлёбка', $text);
        $this->assertStringContainsString('Грибы×4, Вода×2', $text);
        $this->assertStringContainsString('❤️+10', $text);
        $this->assertStringContainsString('⚡+15', $text);
        $this->assertStringContainsString('умнож', $text, 'подпись обязана объяснять эффект умножения');

        // Markdown-safe: парные `*`.
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные * ломают Telegram Markdown-рендер');
    }
}
