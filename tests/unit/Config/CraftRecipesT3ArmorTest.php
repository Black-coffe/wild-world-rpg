<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * S18 (v0.51.200) — recipe config tests для 4 T3 armor (ADR-026 reusable, Фаза 4 3/5).
 *
 * Guarantees:
 *   - Все 4 рецепта существуют в Config\CraftRecipes.
 *   - Каждый имеет output_type='outfit' (для GenericCraftCompletionHandler dispatch
 *     на characters_outfits + updateAgilityAndIntellect, S18 pattern).
 *   - outfit_name_en точно совпадает с outfits.name_en в БД (иначе completion handler
 *     не найдёт row для INSERT в characters_outfits и task завершится тихо без выдачи).
 *   - required_crafted_items включает ProfessionalWorkbench (T3 gate — reusable из S17).
 *   - duration_override_setting_key указывает на existing GameSettings key.
 *   - outfit_slot='body' (все 20 outfits в БД имеют slot='body' — body armor only).
 *   - requires_base=true, agility/intellect bonus, gold/resources заданы.
 *
 * @internal
 */
final class CraftRecipesT3ArmorTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    /**
     * 4 T3 armor + ожидаемые core attributes для each.
     * outfit_name_en должен 1:1 совпадать с outfits.name_en в БД (audit на проде 2026-05-19).
     *
     * @return array<string,array{outfit_name_en:string,required_level:int,outfit_slot:string,duration_setting:string}>
     */
    private function expectedT3Armor(): array
    {
        return [
            'TacticalArmorSuit' => [
                'outfit_name_en'   => 'TacticalArmorSuit',
                'required_level'   => 16,
                'outfit_slot'      => 'body',
                'duration_setting' => 'tier3.armor.tactical_armor_suit.craft_duration_hours',
            ],
            'ExoskeletonStrekoza' => [
                'outfit_name_en'   => 'ExoskeletonStrekoza',
                'required_level'   => 16,
                'outfit_slot'      => 'body',
                'duration_setting' => 'tier3.armor.exoskeleton_strekoza.craft_duration_hours',
            ],
            'TitanPowerArmor' => [
                'outfit_name_en'   => 'TitanPowerArmor',
                'required_level'   => 20,
                'outfit_slot'      => 'body',
                'duration_setting' => 'tier3.armor.titan_power_armor.craft_duration_hours',
            ],
            'TeslaShardArmor' => [
                'outfit_name_en'   => 'TeslaShardArmor',
                'required_level'   => 25,
                'outfit_slot'      => 'body',
                'duration_setting' => 'tier3.armor.tesla_shard_armor.craft_duration_hours',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testAllT3ArmorExist(): void
    {
        foreach (array_keys($this->expectedT3Armor()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertNotNull($recipe, "T3 armor recipe '{$recipeKey}' отсутствует в Config\\CraftRecipes");
        }
    }

    public function testT3ArmorHaveOutfitOutputType(): void
    {
        foreach (array_keys($this->expectedT3Armor()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                'outfit',
                $recipe['output_type'] ?? null,
                "Recipe '{$recipeKey}' должен иметь output_type='outfit' для GenericCraftCompletionHandler dispatch"
            );
        }
    }

    public function testT3ArmorHaveCorrectOutfitNameEn(): void
    {
        foreach ($this->expectedT3Armor() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['outfit_name_en'],
                $recipe['outfit_name_en'] ?? null,
                "Recipe '{$recipeKey}'.outfit_name_en должен совпадать с outfits.name_en в БД"
            );
        }
    }

    public function testT3ArmorRequireProfessionalWorkbench(): void
    {
        foreach (array_keys($this->expectedT3Armor()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $gate   = $recipe['required_crafted_items'] ?? [];
            $this->assertIsArray($gate, "Recipe '{$recipeKey}' должен иметь required_crafted_items");
            $this->assertArrayHasKey(
                'ProfessionalWorkbench',
                $gate,
                "Recipe '{$recipeKey}'.required_crafted_items должен включать ProfessionalWorkbench (T3 gate, reusable из S17)"
            );
            $this->assertSame(1, $gate['ProfessionalWorkbench'], "ProfessionalWorkbench gate qty=1");
        }
    }

    public function testT3ArmorHaveDurationOverrideSettingKey(): void
    {
        foreach ($this->expectedT3Armor() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['duration_setting'],
                $recipe['duration_override_setting_key'] ?? null,
                "Recipe '{$recipeKey}'.duration_override_setting_key должен указывать на GameSettings key"
            );
        }
    }

    public function testT3ArmorHaveCorrectRequiredLevelAndBodySlot(): void
    {
        foreach ($this->expectedT3Armor() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['required_level'],
                $recipe['required_level'] ?? null,
                "Recipe '{$recipeKey}'.required_level mismatch"
            );
            $this->assertSame(
                $expected['outfit_slot'],
                $recipe['outfit_slot'] ?? null,
                "Recipe '{$recipeKey}'.outfit_slot должен быть 'body' (все outfits в БД — body slot)"
            );
        }
    }

    public function testT3ArmorHaveCoreRecipeFields(): void
    {
        $required = [
            'task_name', 'output_type', 'outfit_name_en', 'outfit_slot',
            'required_strength', 'required_level', 'gold_required',
            'resources', 'crafted_items', 'requires_base',
            'required_crafted_items', 'duration_override_setting_key',
            'image_in_progress', 'start_caption_name', 'info_callback',
            'item_name_eng', 'item_name_rus', 'icon_emoji', 'zone_emoji', 'zone_name',
            'agility_bonus', 'intellect_bonus', 'image_completed', 'craft_again_callback',
        ];
        foreach (array_keys($this->expectedT3Armor()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            foreach ($required as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $recipe,
                    "Recipe '{$recipeKey}' missing field: {$field}"
                );
            }
        }
    }

    public function testT3ArmorRequireBase(): void
    {
        foreach (array_keys($this->expectedT3Armor()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertTrue(
                (bool) ($recipe['requires_base'] ?? false),
                "Recipe '{$recipeKey}' должен иметь requires_base=true (T3 crafting привязан к базе)"
            );
        }
    }
}
