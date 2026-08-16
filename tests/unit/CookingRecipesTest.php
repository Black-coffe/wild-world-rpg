<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use App\Services\Notifications\MediaSender;
use App\Services\Tasks\ActionScopeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * V8 (vNext) — anti-drift: меню готовки (CampfireCookingSelect::COOKING_RECIPES)
 * ↔ Config\CraftRecipes. Каждый ключ блюда резолвится в рецепт с согласованными
 * item_name_eng / task_name, без сезонного гейта (готовка круглогодична).
 *
 * @internal
 */
final class CookingRecipesTest extends CIUnitTestCase
{
    public function testCookingRecipesList(): void
    {
        // 5 перишабельных блюд (V8) + 2 консервы (V10).
        $this->assertCount(7, CampfireCookingSelect::COOKING_RECIPES);
        $this->assertSame(
            ['MushroomSoup', 'BerryBrew', 'BakedFruit', 'GrainPorridge', 'HeartyStew', 'StewPreserve', 'DryRation'],
            CampfireCookingSelect::COOKING_RECIPES,
        );
    }

    public function testPerishableVsPreservedFlags(): void
    {
        $cfg     = config('CraftRecipes');
        $meals   = ['MushroomSoup', 'BerryBrew', 'BakedFruit', 'GrainPorridge', 'HeartyStew'];
        $preserves = ['StewPreserve', 'DryRation'];

        foreach ($meals as $key) {
            $r = $cfg->get($key);
            $this->assertTrue(!empty($r['perishable']), "{$key}: блюдо V8 должно быть perishable");
            $this->assertArrayNotHasKey('preserved', $r, "{$key}: блюдо V8 не preserved");
        }
        foreach ($preserves as $key) {
            $r = $cfg->get($key);
            $this->assertTrue(!empty($r['preserved']), "{$key}: консерва должна быть preserved");
            $this->assertArrayNotHasKey('perishable', $r, "{$key}: консерва не perishable");
        }
    }

    /**
     * Тушёнка ОБЯЗАНА требовать мясо: имя, описание в БД («Мясо-овощная тушёнка
     * в закатанной банке») и арт (sealed jars of meat-and-vegetable stew) обещают
     * мясо. До 2026-08-16 рецепт был грибы+зерно+вода — игрок поймал расхождение
     * («автор веган?»). Тест держит имя/описание/арт и рецепт в одной правде.
     */
    public function testStewPreserveRequiresMeat(): void
    {
        $recipe = config('CraftRecipes')->get('StewPreserve');

        $this->assertArrayHasKey('Мясо диких животных', $recipe['resources'] ?? [], 'Тушёнка должна требовать мясо');
        $this->assertGreaterThan(0, $recipe['resources']['Мясо диких животных'], 'кол-во мяса > 0');
    }

    public function testCookingRecipesResolveInConfig(): void
    {
        $cfg = config('CraftRecipes');

        foreach (CampfireCookingSelect::COOKING_RECIPES as $key) {
            $recipe = $cfg->get($key);
            $this->assertIsArray($recipe, "cooking recipe {$key} отсутствует в CraftRecipes");
            $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng mismatch");
            $this->assertSame('craft' . $key, $recipe['task_name'] ?? null, "{$key}: task_name mismatch");
            // Готовка круглогодична — без сезонного гейта.
            $this->assertArrayNotHasKey('required_season', $recipe, "{$key}: cooking не должен иметь required_season");
            // Default crafted_item output (type='drug' через crafted_items).
            $this->assertArrayNotHasKey('output_type', $recipe, "{$key}: cooking output должен быть default crafted_item");
            // Ингредиенты заданы.
            $this->assertIsArray($recipe['resources'] ?? null, "{$key}: resources missing");
            $this->assertNotEmpty($recipe['resources'], "{$key}: resources пусты");
        }
    }

    // ── W23 (ADR-078) — рыбные блюда (дают «Рыбе» применение) ──

    public function testFishRecipesList(): void
    {
        $this->assertCount(3, CampfireCookingSelect::FISH_RECIPES);
        $this->assertSame(
            ['FishSoup', 'GrilledFish', 'FishPreserve'],
            CampfireCookingSelect::FISH_RECIPES,
        );
        // Рыбные НЕ дублируются в базовом меню (показываются только при killswitch).
        $this->assertSame(
            [],
            array_intersect(CampfireCookingSelect::FISH_RECIPES, CampfireCookingSelect::COOKING_RECIPES),
            'рыбные блюда не должны быть в COOKING_RECIPES (гейтятся отдельно)',
        );
    }

    public function testFishRecipesResolveAndRequireFish(): void
    {
        $cfg = config('CraftRecipes');

        foreach (CampfireCookingSelect::FISH_RECIPES as $key) {
            $recipe = $cfg->get($key);
            $this->assertIsArray($recipe, "fish recipe {$key} отсутствует в CraftRecipes");
            $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng mismatch");
            $this->assertSame('craft' . $key, $recipe['task_name'] ?? null, "{$key}: task_name mismatch");
            $this->assertArrayNotHasKey('required_season', $recipe, "{$key}: cooking без сезонного гейта");
            // Главное: рыбное блюдо ОБЯЗАНО требовать «Рыбу» (иначе fishing бессмыслен).
            $this->assertArrayHasKey('Рыба', $recipe['resources'] ?? [], "{$key}: рецепт должен требовать Рыбу");
            $this->assertGreaterThan(0, $recipe['resources']['Рыба'], "{$key}: кол-во Рыбы > 0");
        }
    }

    public function testFishPerishableVsPreserved(): void
    {
        $cfg = config('CraftRecipes');
        foreach (['FishSoup', 'GrilledFish'] as $key) {
            $this->assertTrue(!empty($cfg->get($key)['perishable']), "{$key}: горячее блюдо perishable");
        }
        $this->assertTrue(!empty($cfg->get('FishPreserve')['preserved']), 'FishPreserve: консерва preserved');
    }

    // ── 2026-08-16 — экран разбит надвое (горячее / консервы) ──

    /**
     * Разбиение обязано быть РАЗБИЕНИЕМ: без потерь и без дублей. Иначе блюдо
     * молча выпадет из обоих экранов и станет недостижимым (BUILT-BUT-INVISIBLE).
     */
    public function testHotAndPreserveSplitPartitionsTheMenu(): void
    {
        $this->assertSame(
            CampfireCookingSelect::COOKING_RECIPES,
            array_merge(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::PRESERVE_RECIPES),
            'горячее + консервы должны давать ровно COOKING_RECIPES',
        );
        $this->assertSame(
            [],
            array_intersect(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::PRESERVE_RECIPES),
            'блюдо не может быть одновременно горячим и консервой',
        );
        $this->assertSame(
            CampfireCookingSelect::FISH_RECIPES,
            array_merge(CampfireCookingSelect::FISH_HOT_RECIPES, CampfireCookingSelect::FISH_PRESERVE_RECIPES),
            'рыбное горячее + рыбная консерва должны давать ровно FISH_RECIPES',
        );
    }

    /** Флаг рецепта и экран, на котором он показан, обязаны совпадать. */
    public function testSplitMatchesPerishableFlags(): void
    {
        $cfg = config('CraftRecipes');

        foreach (array_merge(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::FISH_HOT_RECIPES) as $key) {
            $this->assertTrue(!empty($cfg->get($key)['perishable']), "{$key}: на экране горячего — значит perishable");
        }
        foreach (array_merge(CampfireCookingSelect::PRESERVE_RECIPES, CampfireCookingSelect::FISH_PRESERVE_RECIPES) as $key) {
            $this->assertTrue(!empty($cfg->get($key)['preserved']), "{$key}: на экране консервов — значит preserved");
        }
    }

    /**
     * `info_callback` — это кнопка «⬅️ Назад» экрана нехватки сырья
     * ({@see \App\Services\Craft\CraftShortageService}). У консервы она обязана
     * вести на экран консервов, иначе игрок после отказа попадает не туда,
     * откуда пришёл, и своё блюдо в списке не находит.
     */
    public function testPreserveRecipesReturnToPreserveScreen(): void
    {
        $cfg = config('CraftRecipes');

        foreach (array_merge(CampfireCookingSelect::PRESERVE_RECIPES, CampfireCookingSelect::FISH_PRESERVE_RECIPES) as $key) {
            $this->assertSame(CampfireCookingSelect::CB_PRESERVES, $cfg->get($key)['info_callback'] ?? null, "{$key}: назад → экран консервов");
        }
        foreach (array_merge(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::FISH_HOT_RECIPES) as $key) {
            $this->assertSame('cook', $cfg->get($key)['info_callback'] ?? null, "{$key}: назад → экран горячего");
        }
    }

    /** Оба экрана готовки должны быть зарегистрированы в роутере (иначе дверь мёртвая). */
    public function testBothCookingScreensAreRouted(): void
    {
        $routes = config('CallbackRoutes');

        foreach (['cook', CampfireCookingSelect::CB_PRESERVES] as $cb) {
            $this->assertArrayHasKey($cb, $routes->exactRoutes, "callback {$cb} не зарегистрирован");
            $this->assertSame(CampfireCookingSelect::class, $routes->resolve($cb), "callback {$cb} ведёт не туда");
        }
    }

    /**
     * 🔴 Гейт, которого не хватало. До разбиения подпись экрана готовки при живом
     * `cooking.fish_dishes.enabled` доходила до 1206 символов при лимите 1024 —
     * MediaSender штатно уводил экран в текст, и картинку не видел НИКТО. Тест
     * считает ХУДШИЙ случай (рыба включена, все строки шапки на месте, трёхзначные
     * heal-числа) и не даёт длине снова уползти за лимит.
     *
     * Состав блюд и их числа берутся из реального конфига — то есть новый рецепт
     * или удлинившийся список ингредиентов уронит этот тест, а не прод.
     */
    public function testWorstCaseCaptionFitsPhotoLimit(): void
    {
        $cfg = config('CraftRecipes');
        // Самое длинное из двух предупреждений о занятости (🔒 — блокирующий крафт).
        $occupancy = (new ActionScopeService())->occupancyWarning(false);

        $screens = [
            'горячее'  => array_merge(CampfireCookingSelect::HOT_RECIPES, CampfireCookingSelect::FISH_HOT_RECIPES),
            'консервы' => array_merge(CampfireCookingSelect::PRESERVE_RECIPES, CampfireCookingSelect::FISH_PRESERVE_RECIPES),
        ];

        foreach ($screens as $label => $keys) {
            $dishes = [];
            foreach ($keys as $key) {
                $recipe   = $cfg->get($key);
                $dishes[] = [
                    'icon' => $recipe['icon_emoji'] ?? '🍲',
                    'name' => $recipe['item_name_rus'] ?? $key,
                    'cost' => CampfireCookingSelect::costOf($recipe),
                    // Худший случай по разрядности чисел — админ может поднять heal.
                    'hp'    => 999,
                    'tired' => 999,
                ];
            }

            $text = CampfireCookingSelect::renderText(
                $label === 'консервы',
                $dishes,
                $occupancy,
                9,    // freshDays — двузначным не бывает, но берём максимум разряда
                9999, // остаток «Сытости» в минутах
                true, // боевой бонус включён
            );

            $this->assertFalse(
                MediaSender::captionExceedsPhotoLimit($text),
                "экран «{$label}»: подпись " . mb_strlen($text) . " симв. — не влезает в лимит фото, картинка отвалится",
            );

            // Состав блюд неприкосновенен: ужиматься можно только шапкой.
            foreach ($dishes as $d) {
                $this->assertStringContainsString($d['cost'], $text, "экран «{$label}»: пропал состав блюда {$d['name']}");
            }
        }
    }

    /** Ужимание идёт по одной строке и останавливается, как только подпись влезла. */
    public function testFitCaptionDropsOnlyWhatIsNeeded(): void
    {
        $header = "H\n";
        $body   = str_repeat('x', 1000);

        // Влезает сразу — не выкидываем ничего.
        $short = CampfireCookingSelect::fitCaption($header, ['a' => "A\n", 'b' => "B\n"], "short\n", ['a', 'b']);
        $this->assertStringContainsString('A', $short);
        $this->assertStringContainsString('B', $short);

        // Не влезает — уходит ПЕРВЫЙ по приоритету, второй остаётся.
        $long = CampfireCookingSelect::fitCaption($header, ['a' => str_repeat('A', 30) . "\n", 'b' => "B\n"], $body, ['a', 'b']);
        $this->assertStringNotContainsString('AAA', $long);
        $this->assertStringContainsString('B', $long);
        $this->assertStringContainsString($body, $long, 'тело подписи не трогаем никогда');
    }
}
