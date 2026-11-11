<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftCatalog;
use Config\CraftRecipes;

/**
 * D-DEAD-RES волна 2 — пять лекарств первого верстака из хвоста мёртвого сырья.
 *
 * Последние 14 ресурсов без потребителя (срез прода 2026-11-11,
 * docs/specs/d-dead-res-w2/brief.md) становятся сырьём пяти рецептов, сгруппированных
 * по биомам добычи. Тест держит:
 *   - контракт ключа: рецепт есть в CraftRecipes, CraftCatalog, сиде, меню бота, полке Аптечки;
 *   - каждый из 14 — во входах хотя бы одного нового рецепта;
 *   - «крафт не печатает золото»: цена продажи × 1.10 ≤ золото + Σ qty × sell_price;
 *   - лечение — ключи GameSettings medical.<snake>.heal_* с rationale и границами;
 *   - меню «⚕️ Медикаменты» без кнопки одной в ряду.
 *
 * DB-независим: цены предметов и ключи настроек читаются из констант миграций,
 * цены сырья — снимок прода 2026-11-11.
 *
 * @internal
 */
final class CraftRecipesDeadResourcesWave2Test extends CIUnitTestCase
{
    /** name_eng → snake для GameSettings и арта. */
    private const NEW_KEYS = [
        'SealBalm'       => 'seal_balm',
        'ThermalCulture' => 'thermal_culture',
        'CaveTallow'     => 'cave_tallow',
        'DesertSalt'     => 'desert_salt',
        'PainkillerShot' => 'painkiller_shot',
    ];

    /**
     * name → [sell_price, level_required], прод 2026-11-11.
     *
     * @var array<string, array{0: float, 1: int}>
     */
    private const TAIL_RESOURCES = [
        'Лед'                         => [3.80, 2],
        'Фоссилии'                    => [28.50, 15],
        'Пустынный агат'              => [14.25, 6],
        'Миражные кристаллы'          => [9.50, 10],
        'Масло тюленя'                => [7.60, 3],
        'Арктические цветы'           => [14.25, 6],
        'Обсидиан'                    => [6.65, 4],
        'Термофильные микроорганизмы' => [8.55, 5],
        'Природный газ'               => [14.25, 15],
        'Сталактиты и сталагмиты'     => [5.70, 2],
        'Драгоценные камни'           => [19.00, 7],
        'Подземные животные'          => [7.60, 4],
        'Яд животных'                 => [9.50, 4],
        'Медицинское сырьё'           => [209.00, 18],
    ];

    /** Потолок лечения первого верстака (вершина — 40). */
    private const BENCH1_HEAL_CEILING = 40;

    private const ITEMS_MIGRATION    = APPPATH . 'Database/Migrations/2026-12-24-100000_DeadResW2AddTailMedicineItems.php';
    private const SETTINGS_MIGRATION = APPPATH . 'Database/Migrations/2026-12-24-100010_DeadResW2SeedTailMedicineGameSettings.php';
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

    public function testFiveRecipesExistInCraftRecipesAndMedicineCatalog(): void
    {
        $catalog  = new CraftCatalog();
        $medicine = $catalog->benches[CraftCatalog::GENERAL]['categories']['medicine']['recipes'] ?? [];

        foreach (array_keys(self::NEW_KEYS) as $key) {
            $recipe = $this->recipe($key);
            $this->assertSame('craft' . $key, $recipe['task_name'] ?? null, "{$key}: task_name");
            $this->assertSame($key, $recipe['item_name_eng'] ?? null, "{$key}: item_name_eng");
            $this->assertSame('genericCraft_' . $key . '_1', $recipe['craft_again_callback'] ?? null, "{$key}: craft_again_callback");
            $this->assertSame('craftPreviewT3Medical_' . $key, $recipe['info_callback'] ?? null, "{$key}: info_callback");
            $this->assertSame([], $recipe['required_crafted_items'] ?? [], "{$key}: первый верстак без гейта профессионального");
            $this->assertContains($key, $medicine, "{$key}: нет в CraftCatalog general/medicine");
        }
    }

    public function testEachTailResourceFeedsAtLeastOneNewRecipe(): void
    {
        $consumed = [];
        foreach (array_keys(self::NEW_KEYS) as $key) {
            $resources = $this->recipe($key)['resources'] ?? [];
            $this->assertIsArray($resources);
            foreach ($resources as $name => $qty) {
                $this->assertArrayHasKey($name, self::TAIL_RESOURCES, "{$key}: сырьё «{$name}» не из хвоста");
                $this->assertIsInt($qty);
                $this->assertGreaterThan(0, $qty);
                $consumed[$name] = true;
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys(self::TAIL_RESOURCES), array_keys($consumed))),
            'Ресурс хвоста без потребителя');
    }

    public function testRequiredLevelLetsEveryHolderCraft(): void
    {
        foreach (array_keys(self::NEW_KEYS) as $key) {
            $recipe = $this->recipe($key);
            $max    = 0;
            foreach (array_keys((array) ($recipe['resources'] ?? [])) as $name) {
                $max = max($max, self::TAIL_RESOURCES[$name][1]);
            }
            $this->assertSame($max, $recipe['required_level'] ?? null, "{$key}: уровень = максимум level_required сырья");
        }
    }

    public function testCraftDoesNotPrintGold(): void
    {
        $this->assertFileExists(self::ITEMS_MIGRATION);
        require_once self::ITEMS_MIGRATION;
        $items = \App\Database\Migrations\DeadResW2AddTailMedicineItems::ITEMS;

        $prices = [];
        foreach ($items as $item) {
            $prices[$item['name_eng']] = (float) $item['price'];
            $this->assertSame('drug', $item['type'] ?? 'drug', "{$item['name_eng']}: type");
        }

        foreach (array_keys(self::NEW_KEYS) as $key) {
            $this->assertArrayHasKey($key, $prices, "{$key}: нет строки crafted_items в сиде");
            $recipe = $this->recipe($key);
            $input  = (float) ($recipe['gold_required'] ?? 0);
            foreach ((array) ($recipe['resources'] ?? []) as $name => $qty) {
                $input += (int) $qty * self::TAIL_RESOURCES[$name][0];
            }
            $this->assertSame([], (array) ($recipe['crafted_items'] ?? []), "{$key}: только сырьё");
            $this->assertLessThanOrEqual(
                round($input, 2),
                round($prices[$key] * 1.10, 2),
                "{$key}: продажа × 1.10 = " . ($prices[$key] * 1.10) . " > вход {$input} — крафт печатает золото"
            );
        }
    }

    public function testHealComesFromGameSettingsWithinBenchCeiling(): void
    {
        $this->assertFileExists(self::SETTINGS_MIGRATION);
        require_once self::SETTINGS_MIGRATION;
        $rows   = \App\Database\Migrations\DeadResW2SeedTailMedicineGameSettings::ROWS;
        $shared = \App\Database\Migrations\DeadResW2SeedTailMedicineGameSettings::SHARED;

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['setting_key']] = $row;
        }

        foreach (self::NEW_KEYS as $snake) {
            foreach (['heal_health', 'heal_tired'] as $field) {
                $key = "medical.{$snake}.{$field}";
                $this->assertArrayHasKey($key, $byKey, "нет ключа {$key}");
                $row = $byKey[$key];
                foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'default_value_text'] as $text) {
                    $this->assertNotSame('', trim((string) ($row[$text] ?? '')), "{$key}: пустое {$text}");
                }
                $this->assertSame((string) $row['value_int'], $row['default_value_text'], "{$key}: default = value");
                $this->assertLessThanOrEqual(self::BENCH1_HEAL_CEILING, $row['value_int'], "{$key}: выше потолка первого верстака");
            }
        }

        $this->assertSame(40, $byKey['medical.painkiller_shot.heal_health']['value_int'] ?? null, 'Укол: 40 здоровья');
        $this->assertSame(15, $byKey['medical.painkiller_shot.heal_tired']['value_int'] ?? null, 'Укол: 15 выносливости');

        foreach (['recommended_min', 'recommended_max', 'hard_min', 'hard_max', 'category'] as $f) {
            $this->assertArrayHasKey($f, $shared);
        }
    }

    public function testEachNewDrugStandsOnThePharmacyShelf(): void
    {
        foreach (array_keys(self::NEW_KEYS) as $key) {
            $this->assertContains($key, \Config\Consumables::MEDICINE, "{$key} не на полке 💊 Аптечка");
            $this->assertNotContains($key, \Config\Consumables::PROVISION, "{$key} попал в провизию");
        }
    }

    public function testBotMedicineMenuOpensEachNewRecipeCardWithoutSoloRows(): void
    {
        $src = (string) file_get_contents(self::BOT_MENU);
        foreach (array_keys(self::NEW_KEYS) as $key) {
            $this->assertStringContainsString("'craftPreviewT3Medical_{$key}'", $src, "{$key}: нет кнопки в «Медикаментах»");
        }

        // Ряд меню — `[` на своей строке, затем строки `[ 'label' => …],`, затем `],`.
        preg_match_all("/^\\s*\\[\\s*\\n((?:\\s*\\[\\s*'label'.*\\n)+)\\s*\\],?\\s*$/m", $src, $rows);
        $this->assertNotEmpty($rows[1], 'не нашёл ряды меню');
        $total = 0;
        foreach ($rows[1] as $i => $row) {
            $buttons = substr_count($row, "'label'");
            $total += $buttons;
            $this->assertGreaterThanOrEqual(2, $buttons, "ряд {$i}: кнопка одна в ряду");
        }
        $this->assertSame(17, $total, 'в «Медикаментах» 17 кнопок');
    }
}
