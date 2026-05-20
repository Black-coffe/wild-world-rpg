<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * S19 (v0.51.201) — recipe config tests для 3 T3 medical (ADR-026 reusable, Фаза 4 4/5).
 *
 * Guarantees:
 *   - Все 3 рецепта существуют в Config\CraftRecipes.
 *   - output_type НЕ задан → default 'crafted_item' path в GenericCraftCompletionHandler
 *     (drug пишется в crafted_items_log; в отличие от S17 weapon / S18 outfit).
 *   - item_name_eng точно совпадает с crafted_items.name_eng (миграция S19AddT3MedicalItems).
 *   - required_crafted_items включает ProfessionalWorkbench (T3 gate — reusable из S17/S18).
 *   - duration_override_setting_key указывает на existing GameSettings key (tier3.medical.*).
 *   - info_callback использует prefix craftPreviewT3Medical_ (route в CallbackRoutes).
 *   - zone_name='медицина', requires_base=true, gold/resources/bonus заданы.
 *
 * @internal
 */
final class CraftRecipesT3MedicalTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    /**
     * 3 T3 medical + ожидаемые core attributes.
     * item_name_eng должен 1:1 совпадать с crafted_items.name_eng (миграция S19).
     *
     * @return array<string,array{item_name_eng:string,required_level:int,duration_setting:string}>
     */
    private function expectedT3Medical(): array
    {
        return [
            'SyntheticMedicine' => [
                'item_name_eng'    => 'SyntheticMedicine',
                'required_level'   => 20,
                'duration_setting' => 'tier3.medical.synthetic_medicine.craft_duration_hours',
            ],
            'EmergencyTransfusion' => [
                'item_name_eng'    => 'EmergencyTransfusion',
                'required_level'   => 18,
                'duration_setting' => 'tier3.medical.emergency_transfusion.craft_duration_hours',
            ],
            'SurgicalKit' => [
                'item_name_eng'    => 'SurgicalKit',
                'required_level'   => 22,
                'duration_setting' => 'tier3.medical.surgical_kit.craft_duration_hours',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testAllT3MedicalExist(): void
    {
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertNotNull($recipe, "T3 medical recipe '{$recipeKey}' отсутствует в Config\\CraftRecipes");
        }
    }

    public function testT3MedicalUseDefaultCraftedItemOutput(): void
    {
        // output_type НЕ задан → GenericCraftCompletionHandler default crafted_item path.
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertArrayNotHasKey(
                'output_type',
                $recipe,
                "Recipe '{$recipeKey}' НЕ должен задавать output_type (drug = default crafted_item path)"
            );
        }
    }

    public function testT3MedicalHaveCorrectItemNameEng(): void
    {
        foreach ($this->expectedT3Medical() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['item_name_eng'],
                $recipe['item_name_eng'] ?? null,
                "Recipe '{$recipeKey}'.item_name_eng должен совпадать с crafted_items.name_eng (миграция S19)"
            );
        }
    }

    public function testT3MedicalRequireProfessionalWorkbench(): void
    {
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $gate   = $recipe['required_crafted_items'] ?? [];
            $this->assertIsArray($gate, "Recipe '{$recipeKey}' должен иметь required_crafted_items");
            $this->assertArrayHasKey(
                'ProfessionalWorkbench',
                $gate,
                "Recipe '{$recipeKey}'.required_crafted_items должен включать ProfessionalWorkbench (T3 gate)"
            );
            $this->assertSame(1, $gate['ProfessionalWorkbench'], "ProfessionalWorkbench gate qty=1");
        }
    }

    public function testT3MedicalHaveDurationOverrideSettingKey(): void
    {
        foreach ($this->expectedT3Medical() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['duration_setting'],
                $recipe['duration_override_setting_key'] ?? null,
                "Recipe '{$recipeKey}'.duration_override_setting_key должен указывать на GameSettings key"
            );
        }
    }

    public function testT3MedicalHaveCorrectRequiredLevel(): void
    {
        foreach ($this->expectedT3Medical() as $recipeKey => $expected) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                $expected['required_level'],
                $recipe['required_level'] ?? null,
                "Recipe '{$recipeKey}'.required_level mismatch"
            );
        }
    }

    public function testT3MedicalInfoCallbackUsesPreviewPrefix(): void
    {
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe   = $this->cfg->get($recipeKey);
            $callback = $recipe['info_callback'] ?? '';
            $this->assertSame(
                'craftPreviewT3Medical_' . $recipeKey,
                $callback,
                "Recipe '{$recipeKey}'.info_callback должен быть craftPreviewT3Medical_{$recipeKey} (CallbackRoutes prefix)"
            );
        }
    }

    public function testT3MedicalAreInMedicineZone(): void
    {
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertSame(
                'медицина',
                $recipe['zone_name'] ?? null,
                "Recipe '{$recipeKey}'.zone_name должен быть 'медицина'"
            );
        }
    }

    public function testT3MedicalHaveCoreRecipeFields(): void
    {
        $required = [
            'task_name', 'required_level', 'gold_required',
            'resources', 'crafted_items', 'requires_base',
            'required_crafted_items', 'duration_override_setting_key',
            'image_in_progress', 'start_caption_name', 'info_callback',
            'item_name_eng', 'item_name_rus', 'icon_emoji', 'zone_emoji', 'zone_name',
            'agility_bonus', 'intellect_bonus', 'image_completed', 'craft_again_callback',
        ];
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
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

    public function testT3MedicalRequireBase(): void
    {
        foreach (array_keys($this->expectedT3Medical()) as $recipeKey) {
            $recipe = $this->cfg->get($recipeKey);
            $this->assertTrue(
                (bool) ($recipe['requires_base'] ?? false),
                "Recipe '{$recipeKey}' должен иметь requires_base=true (T3 crafting привязан к базе)"
            );
        }
    }
}
