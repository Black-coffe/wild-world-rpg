<?php

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * E16 Ф2 (ROADMAP-100, ADR-116/121) — 2 грандмастер-рецепта (гейт L40).
 *
 * Замыкают нить охота(E15)→реликвии + север(E16)→Пепел/Кристалл → грандмастер-крафт
 * двух craftless легендарок (HydraPlasmaCannon L26 weapon + JuggernautBattleArmor L22 outfit).
 *
 * Инварианты:
 *   - Оба рецепта существуют, output_type weapon/outfit, name_en совпадает с легендаркой в БД.
 *   - required_level=40 (грандмастер-band E13), ProfessionalWorkbench gate, requires_base.
 *   - duration_override_setting_key (длительности в GameSettings).
 *   - 🔑 СИНК: оба ПОТРЕБЛЯЮТ северные трофеи (Пепел Предтеч + Кристалл Разлома + Древние
 *     реликвии) — иначе пояс/реликвии остаются без crafting-применения (петля разорвана).
 *
 * @internal
 */
final class CraftRecipesGrandmasterTest extends CIUnitTestCase
{
    private CraftRecipes $cfg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CraftRecipes();
    }

    public function testHydraPlasmaCannonRecipeExists(): void
    {
        $r = $this->cfg->get('HydraPlasmaCannon');
        $this->assertNotNull($r, 'Грандмастер-рецепт HydraPlasmaCannon отсутствует');
        $this->assertSame('weapon', $r['output_type'] ?? null);
        $this->assertSame('HydraPlasmaCannon', $r['weapon_name_en'] ?? null);
        $this->assertSame(40, $r['required_level'] ?? null, 'грандмастер-band L40');
        $this->assertSame('twohand', $r['weapon_slot'] ?? null);
        $this->assertSame('tier3.weapons.hydra_plasma_cannon.craft_duration_hours', $r['duration_override_setting_key'] ?? null);
    }

    public function testJuggernautBattleArmorRecipeExists(): void
    {
        $r = $this->cfg->get('JuggernautBattleArmor');
        $this->assertNotNull($r, 'Грандмастер-рецепт JuggernautBattleArmor отсутствует');
        $this->assertSame('outfit', $r['output_type'] ?? null);
        $this->assertSame('JuggernautBattleArmor', $r['outfit_name_en'] ?? null);
        $this->assertSame(40, $r['required_level'] ?? null, 'грандмастер-band L40');
        $this->assertSame('body', $r['outfit_slot'] ?? null);
        $this->assertSame('tier3.armor.juggernaut_battle_armor.craft_duration_hours', $r['duration_override_setting_key'] ?? null);
    }

    public function testBothRequireProfessionalWorkbenchAndBase(): void
    {
        foreach (['HydraPlasmaCannon', 'JuggernautBattleArmor'] as $key) {
            $r    = $this->cfg->get($key);
            $gate = $r['required_crafted_items'] ?? [];
            $this->assertIsArray($gate);
            $this->assertArrayHasKey('ProfessionalWorkbench', $gate, "{$key}: T3 gate");
            $this->assertTrue((bool) ($r['requires_base'] ?? false), "{$key}: requires_base");
        }
    }

    /**
     * 🔑 Главный инвариант E16 Ф2 — оба грандмастер-рецепта = СИНК северных трофеев.
     * Без этого пояс (E16) и реликвии элиток (E15) остаются без crafting-применения.
     */
    public function testGrandmasterRecipesConsumeNorthTrophies(): void
    {
        $northIngredients = ['Пепел Предтеч', 'Кристалл Разлома', 'Древние реликвии'];
        foreach (['HydraPlasmaCannon', 'JuggernautBattleArmor'] as $key) {
            $r         = $this->cfg->get($key);
            $resources = $r['resources'] ?? [];
            $this->assertIsArray($resources);
            foreach ($northIngredients as $ing) {
                $this->assertArrayHasKey(
                    $ing,
                    $resources,
                    "Грандмастер-рецепт '{$key}' ОБЯЗАН потреблять '{$ing}' (синк севера; иначе петля E15→E16→крафт разорвана)"
                );
                $this->assertGreaterThan(0, $resources[$ing], "{$key}: '{$ing}' qty>0");
            }
        }
    }

    public function testGrandmasterTaskNamesUnique(): void
    {
        $this->assertSame('craftHydraPlasmaCannon', $this->cfg->get('HydraPlasmaCannon')['task_name'] ?? null);
        $this->assertSame('craftJuggernautBattleArmor', $this->cfg->get('JuggernautBattleArmor')['task_name'] ?? null);
    }
}
