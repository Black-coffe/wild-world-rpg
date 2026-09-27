<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Telegram\Commands\Actions\Craft\Cooking\CampfireCookingSelect;
use App\Services\Player\CraftService;
use App\Services\World\SeasonalCraftService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftCatalog;
use Config\CraftRecipes;

/**
 * W2.N3-03 — индекс веб-крафта ({@see CraftCatalog}) совпадает с деревом экранов бота.
 *
 * Дерево бота читается из исходников его экранов: строковые литералы кода (комментарии не в счёт —
 * закомментированные кнопки брони не должны «появляться»), каждый литерал, который есть
 * `info_callback` рецепта, — кнопка рецепта. DB-независим.
 *
 * @internal
 */
final class CraftCatalogTest extends CIUnitTestCase
{
    private const ACTIONS = 'app/Controllers/Telegram/Commands/Actions/';

    /** Экран бота каждой категории с собственным экраном. */
    private const CATEGORY_SCREENS = [
        'general.medicine'          => 'Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php',
        'general.tools'             => 'Craft/WorkbenchGeneral/Tools/ToolsCraft1Action.php',
        'general.components'        => 'Craft/WorkbenchGeneral/Components/ComponentsCraft1Select.php',
        'general.workbenches'       => 'Craft/WorkbenchGeneral/Workbench/WorkbenchCraft1Select.php',
        'general.defense'           => 'Craft/WorkbenchGeneral/Defense/DefenseCraft1Select.php',
        'standard.robots'           => 'Craft/WorkbenchStandard/Robots/RobotsCraft2Select.php',
        'standard.teleports'        => 'Craft/WorkbenchStandard/TeleportBeacon/TeleportBeaconCraft2Select.php',
        'standard.armor'            => 'Craft/WorkbenchStandard/Armor/ArmorCraft2Select.php',
        'standard.weapons'          => 'Craft/WorkbenchStandard/Weapons/WeaponsCraft2Select.php',
        'standard.drones'           => 'Craft/WorkbenchStandard/StandardCraftingAction.php',
        'pro.weapons'               => 'Craft/WorkbenchProfessional/WeaponsCraftT3Select.php',
        'pro.armor'                 => 'Craft/WorkbenchProfessional/ArmorCraftT3Select.php',
        'pro.medical'               => 'Craft/WorkbenchProfessional/MedicalCraftT3Select.php',
        'pro.utility'               => 'Craft/WorkbenchProfessional/UtilityCraftT3Select.php',
        'pro.faction_weapons'       => 'Craft/WorkbenchProfessional/FactionWeaponsCraftSelect.php',
        'pro.faction_armor'         => 'Craft/WorkbenchProfessional/FactionArmorCraftSelect.php',
    ];

    /** Экран раздела в боте — на нём стоят кнопки категорий. */
    private const BENCH_SCREENS = [
        CraftCatalog::GENERAL  => 'Craft/WorkbenchGeneral/GeneralCraftingAction.php',
        CraftCatalog::STANDARD => 'Craft/WorkbenchStandard/StandardCraftingAction.php',
        CraftCatalog::PRO      => 'Craft/WorkbenchGeneral/Workbench/WorkbenchProfessionalAction.php',
    ];

    public function testEveryCatalogKeyIsARecipe(): void
    {
        $recipes = new CraftRecipes();
        $missing = [];
        $seen    = [];
        foreach ((new CraftCatalog())->benches as $benchKey => $bench) {
            foreach ($bench['categories'] as $catKey => $cat) {
                $this->assertNotSame([], $cat['recipes'], "{$benchKey}.{$catKey} пуста");
                foreach ($cat['recipes'] as $key) {
                    if ($recipes->get($key) === null) {
                        $missing[] = "{$benchKey}.{$catKey}.{$key}";
                    }
                    $this->assertArrayNotHasKey($key, $seen, "{$key} дважды в индексе");
                    $seen[$key] = true;
                }
            }
        }
        $this->assertSame([], $missing, 'ключи индекса, которых нет в CraftRecipes');
    }

    public function testCategoryTreesMatchTheBotScreens(): void
    {
        $catalog = new CraftCatalog();
        $byInfo  = $this->recipesByInfoCallback();

        foreach (self::CATEGORY_SCREENS as $path => $file) {
            [$benchKey, $catKey] = explode('.', $path);
            $nav = $this->navCallbacks($catalog, $benchKey);
            $bot = [];
            foreach ($this->literals(self::ACTIONS . $file) as $literal) {
                if (! isset($nav[$literal])) {
                    array_push($bot, ...($byInfo[$literal] ?? []));
                }
            }
            $this->assertSame(
                $bot,
                $catalog->benches[$benchKey]['categories'][$catKey]['recipes'],
                "{$path}: рецепты и порядок — как кнопки экрана бота {$file}"
            );
        }
    }

    public function testCookingAndSeasonalCategoriesMatchTheirBotLists(): void
    {
        $cats = (new CraftCatalog())->benches[CraftCatalog::GENERAL]['categories'];

        $this->assertSame([...CampfireCookingSelect::HOT_RECIPES, ...CampfireCookingSelect::FISH_HOT_RECIPES], $cats['cook']['recipes']);
        $this->assertSame([...CampfireCookingSelect::PRESERVE_RECIPES, ...CampfireCookingSelect::FISH_PRESERVE_RECIPES], $cats['preserves']['recipes']);
        $this->assertSame(array_merge(...array_values(SeasonalCraftService::SEASON_RECIPES)), $cats['seasonal']['recipes']);
        $this->assertTrue($cats['seasonal']['seasonal'] ?? false);
    }

    public function testBenchScreensCarryTheCategoryButtonsAndTheHubCarriesTheBenches(): void
    {
        $catalog = new CraftCatalog();
        foreach (self::BENCH_SCREENS as $benchKey => $file) {
            $literals = $this->literals(self::ACTIONS . $file);
            foreach ($catalog->benches[$benchKey]['categories'] as $catKey => $cat) {
                if ($cat['bot'] !== null) {
                    $this->assertContains($cat['bot'], $literals, "{$benchKey}.{$catKey}: кнопки категории нет на экране раздела");
                }
            }
        }

        foreach ([true, false] as $hasPro) {
            $hub = [];
            foreach (CraftService::hubRows($hasPro, false, false) as $row) {
                foreach ($row as $btn) {
                    $hub[] = $btn['callback_data'] ?? '';
                }
            }
            foreach ($catalog->benches as $benchKey => $bench) {
                $this->assertContains($bench['bot'], $hub, "{$benchKey}: раздела нет в хабе крафта");
            }
        }
    }

    public function testLockPointsAtARecipeOfTheCatalog(): void
    {
        $catalog = new CraftCatalog();
        $lock    = $catalog->benches[CraftCatalog::PRO]['lock'];
        $this->assertNotNull($lock);
        $this->assertContains($lock['recipe'], $catalog->benches[$lock['bench']]['categories'][$lock['cat']]['recipes']);
        $this->assertSame($lock['item'], (new CraftRecipes())->get($lock['recipe'])['item_name_eng'] ?? null, 'замок снимает тот предмет, который собирает рецепт');
        $this->assertNull($catalog->benches[CraftCatalog::GENERAL]['lock']);
        $this->assertNull($catalog->benches[CraftCatalog::STANDARD]['lock'], 'бот не запирает стандартный раздел');
    }

    public function testBotRouteWalksBenchCategoryCard(): void
    {
        $catalog = new CraftCatalog();
        $this->assertSame(['generalCraft', 'medicinesCraft1', 'bandage'], $catalog->botRoute('Bandage', 'bandage'));
        $this->assertSame(['generalCraft', 'cook', 'cook_qty_MushroomSoup'], $catalog->botRoute('MushroomSoup', 'cook'));
        $this->assertSame(['standardCraft', 'droneScout'], $catalog->botRoute('DroneScout', 'droneScout'));
        $this->assertSame(['workbenchProfessional', 'craftWeaponsT3Select', 'craftPreviewT3_GaussPistol'], $catalog->botRoute('GaussPistol', 'craftPreviewT3_GaussPistol'));
        $this->assertNull($catalog->botRoute('Nope', 'x'));
    }

    /** @return array<string, list<string>> info_callback → ключи рецептов (порядок конфига) */
    private function recipesByInfoCallback(): array
    {
        $out = [];
        foreach ((new CraftRecipes())->recipes as $key => $recipe) {
            $cb = is_array($recipe) ? ($recipe['info_callback'] ?? null) : null;
            if (is_string($cb) && $cb !== '') {
                $out[$cb][] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Callback'и своего раздела и его категорий — на экранах бота это «назад», а не кнопки рецептов
     * (у «🔬 Верстаков» кнопка чужого раздела `workbenchProfessional` — карточка цеха, она считается).
     *
     * @return array<string, true>
     */
    private function navCallbacks(CraftCatalog $catalog, string $benchKey): array
    {
        $bench = $catalog->benches[$benchKey];
        $nav   = [$bench['bot'] => true];
        foreach ($bench['categories'] as $cat) {
            if ($cat['bot'] !== null) {
                $nav[$cat['bot']] = true;
            }
        }

        return $nav;
    }

    /**
     * Строковые литералы кода файла по порядку, без повторов; комментарии не читаются.
     *
     * @return list<string>
     */
    private function literals(string $file): array
    {
        $src = file_get_contents(ROOTPATH . $file);
        $this->assertIsString($src, $file);
        $out = [];
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = substr($token[1], 1, -1);
                if (! in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }
}
