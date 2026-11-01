<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftCatalog;
use Config\CraftRecipes;

/**
 * D-DEAD-RES — четыре лекарства первого верстака из мёртвого сырья.
 *
 * Семь самых накопленных ресурсов без потребителя (срез прода 2026-11-01,
 * docs/specs/d-srez/report.md §2) становятся сырьём новых рецептов. Тест держит:
 *   - контракт ключа: рецепт есть в CraftRecipes, CraftCatalog, сидах и меню бота;
 *   - каждое из семи — во входах хотя бы одного нового рецепта;
 *   - «крафт не печатает золото»: цена продажи × 1.10 ≤ золото + Σ qty × sell_price;
 *   - лечение — ключи GameSettings medical.<snake>.heal_* с rationale и границами.
 *
 * DB-независим: цены предметов и ключи настроек читаются из констант миграций,
 * цены сырья — снимок прода 2026-11-01 (на preprod «Вулканический пепел» 13.30
 * против 3.80 на проде — берём прод: так правило строже для мази).
 *
 * @internal
 */
final class CraftRecipesDeadResourcesTest extends CIUnitTestCase
{
    private const NEW_KEYS = ['Antivenom', 'ChitinPatch', 'SulfurOintment', 'MineralTablets'];

    /**
     * name → [sell_price, level_required], прод 2026-11-01.
     *
     * @var array<string, array{0: float, 1: int}>
     */
    private const DEAD_RESOURCES = [
        'Змеиный яд'            => [11.40, 5],
        'Тропические насекомые' => [1.90, 1],
        'Редкие руды'           => [19.00, 7],
        'Снег'                  => [0.95, 1],
        'Капельники'            => [2.85, 2],
        'Сера'                  => [5.70, 5],
        'Вулканический пепел'   => [3.80, 2],
    ];

    private const ITEMS_MIGRATION    = APPPATH . 'Database/Migrations/2026-12-23-100000_DeadResAddFieldMedicineItems.php';
    private const SETTINGS_MIGRATION = APPPATH . 'Database/Migrations/2026-12-23-100010_DeadResSeedFieldMedicineGameSettings.php';
    private const BOT_MENU           = APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchGeneral/Medical/MedicalCraft1Action.php';

    /**
     * @return array<string, mixed>
     */
    private function recipe(string $key): array
    {
        $recipe = (new CraftRecipes())->get($key);
        $this->assertIsArray($recipe, "Рецепта {$key} нет в Config\\CraftRecipes");

        return $recipe;
    }

    public function testFourRecipesExistInCraftRecipesAndMedicineCatalog(): void
    {
        $catalog  = new CraftCatalog();
        $medicine = $catalog->benches[CraftCatalog::GENERAL]['categories']['medicine']['recipes'] ?? [];

        foreach (self::NEW_KEYS as $key) {
            $recipe = $this->recipe($key);
            $this->assertSame('craft' . $key, $recipe['task_name'] ?? null, "{$key}: task_name");
            $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng");
            $this->assertSame('genericCraft_' . $key . '_1', $recipe['craft_again_callback'] ?? null, "{$key}: craft_again_callback");
            $this->assertSame('craftPreviewT3Medical_' . $key, $recipe['info_callback'] ?? null, "{$key}: info_callback");
            $this->assertSame([], $recipe['required_crafted_items'] ?? [], "{$key}: первый верстак без гейта профессионального");
            $this->assertContains($key, $medicine, "{$key}: нет в CraftCatalog general/medicine");
        }
    }

    public function testEachDeadResourceFeedsAtLeastOneNewRecipe(): void
    {
        $consumed = [];
        foreach (self::NEW_KEYS as $key) {
            $resources = $this->recipe($key)['resources'] ?? [];
            $this->assertIsArray($resources);
            foreach ($resources as $name => $qty) {
                $this->assertArrayHasKey($name, self::DEAD_RESOURCES, "{$key}: сырьё «{$name}» не из семи мёртвых");
                $this->assertIsInt($qty);
                $this->assertGreaterThan(0, $qty);
                $consumed[$name] = true;
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::DEAD_RESOURCES), array_keys($consumed))),
            'Мёртвый ресурс без потребителя');
    }

    public function testRequiredLevelLetsEveryHolderCraft(): void
    {
        foreach (self::NEW_KEYS as $key) {
            $recipe = $this->recipe($key);
            $max    = 0;
            foreach (array_keys((array) ($recipe['resources'] ?? [])) as $name) {
                $max = max($max, self::DEAD_RESOURCES[$name][1]);
            }
            $this->assertSame($max, $recipe['required_level'] ?? null, "{$key}: уровень = максимум level_required сырья");
        }
    }

    public function testCraftDoesNotPrintGold(): void
    {
        $this->assertFileExists(self::ITEMS_MIGRATION);
        require_once self::ITEMS_MIGRATION;
        $items = \App\Database\Migrations\DeadResAddFieldMedicineItems::ITEMS;

        $prices = [];
        foreach ($items as $item) {
            $prices[$item['name_eng']] = (float) $item['price'];
        }

        foreach (self::NEW_KEYS as $key) {
            $this->assertArrayHasKey($key, $prices, "{$key}: нет строки crafted_items в сиде");
            $recipe = $this->recipe($key);
            $input  = (float) ($recipe['gold_required'] ?? 0);
            foreach ((array) ($recipe['resources'] ?? []) as $name => $qty) {
                $input += (int) $qty * self::DEAD_RESOURCES[$name][0];
            }
            $this->assertSame([], (array) ($recipe['crafted_items'] ?? []), "{$key}: только сырьё");
            $this->assertLessThanOrEqual(
                round($input, 2),
                round($prices[$key] * 1.10, 2),
                "{$key}: продажа × 1.10 = " . ($prices[$key] * 1.10) . " > вход {$input} — крафт печатает золото"
            );
        }

        foreach ($items as $item) {
            $this->assertSame('drug', $item['type'] ?? 'drug', "{$item['name_eng']}: type");
        }
    }

    public function testHealComesFromGameSettingsWithRationaleAndBounds(): void
    {
        $this->assertFileExists(self::SETTINGS_MIGRATION);
        require_once self::SETTINGS_MIGRATION;
        $rows   = \App\Database\Migrations\DeadResSeedFieldMedicineGameSettings::ROWS;
        $shared = \App\Database\Migrations\DeadResSeedFieldMedicineGameSettings::SHARED;

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['setting_key']] = $row;
        }

        foreach (['antivenom', 'chitin_patch', 'sulfur_ointment', 'mineral_tablets'] as $snake) {
            foreach (['heal_health', 'heal_tired'] as $field) {
                $key = "medical.{$snake}.{$field}";
                $this->assertArrayHasKey($key, $byKey, "нет ключа {$key}");
                $row = $byKey[$key];
                foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'default_value_text'] as $text) {
                    $this->assertNotSame('', trim((string) ($row[$text] ?? '')), "{$key}: пустое {$text}");
                }
                $this->assertSame((string) $row['value_int'], $row['default_value_text'], "{$key}: default = value");
            }
        }

        foreach (['recommended_min', 'recommended_max', 'hard_min', 'hard_max', 'category'] as $f) {
            $this->assertArrayHasKey($f, $shared);
        }
    }

    /**
     * ADR-022: у каждого рецепта своя картинка на диске и в реестре генерации.
     */
    public function testEachRecipeHasArtOnDiskAndInImageRegistry(): void
    {
        $registry = [];
        foreach ((new \Config\ImageRegistry())->images as $entry) {
            $registry[$entry['file']] = $entry;
        }

        foreach (self::NEW_KEYS as $key) {
            $recipe = $this->recipe($key);
            foreach (['image_in_progress', 'image_completed'] as $field) {
                $rel = $recipe[$field] ?? null;
                $this->assertIsString($rel, "{$key}: {$field}");
                $this->assertFileExists(FCPATH . $rel, "{$key}: {$field} нет на диске");
                $this->assertArrayHasKey($rel, $registry, "{$key}: {$rel} нет в Config\\ImageRegistry");
                $this->assertSame('loot.medicine', $registry[$rel]['lexicon'] ?? null, "{$key}: lexicon");
            }
        }
    }

    public function testBotMedicineMenuOpensEachNewRecipeCard(): void
    {
        $src = (string) file_get_contents(self::BOT_MENU);
        foreach (self::NEW_KEYS as $key) {
            $this->assertStringContainsString("'craftPreviewT3Medical_{$key}'", $src, "{$key}: нет кнопки в «Лекарствах»");
        }
    }
}
