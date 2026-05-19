<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S12 (v0.51.194) — seed GameSettings keys для BlastFurnace level effects (ROADMAP-CRAFT §6).
 *
 * До S12 уровень BlastFurnace = pure косметика (S3 wired UI suffix L<n>, нет
 * gameplay-эффекта; `buildings.effects = "smelt_metal"` — dead string).
 *
 * **Semantics: pure bonus (user pick 2026-05-19).** Без BlastFurnace игроки
 * продолжают крафтить MetalFragments как раньше (baseline yield). При наличии
 * L2+ — bonus от cascade lookup. No breaking change для 6+ chars без BlastFurnace.
 *
 * Pattern (reuse S11): `building.<lower_name_en>.l<N>.<param_name>`.
 * Read: `BuildingEffectsService::getCraftYieldMultiplier($charId, 'BlastFurnace')`.
 * L4-L10 cascade на L3 multiplier (plateau, як у S11 Workshop).
 *
 * Recipes що отримують boost — позначаються полем `boost_building` у
 * `Config\CraftRecipes.php`. S12: тільки `MetalFragments`. Будущі rec'ы можуть
 * вказувати інший boost building через те ж поле.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 *
 * Idempotent: пропускає існуючі setting_key.
 */
class S12SeedBlastFurnaceGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.blastfurnace.l2.craft_yield_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 1.15,
                'default_value_text' => '1.15',
                'rationale_text'     => '+15% yield на L2 — ощутимый бонус (qty=10→11, qty=50→58), но не настолько крупный чтобы L1 chars чувствовали себя «лишёнными». Контракт «BlastFurnace L2 окупается за ~10-15 переплавок» для типичного gameplay loop.',
                'effect_text'        => 'Множник quantity вихідних MetalFragments (та інших recipe з boost_building=BlastFurnace) у GenericCraftCompletionHandler::handle() — застосовується після extractQuantity. Формула: qty_final = max(qty_base, round(qty_base × multiplier)).',
                'above_effect_text'  => 'При 1.30 — L2 уже дає більше за ROADMAP-планований L3 (+35% bonus за 50k gold L2 + 75k L3 = 125k золота за L2-only ефект). Ламає progression curve.',
                'below_effect_text'  => 'При 1.05 — bonus +5% непомітний для qty<20 (округление з\'їдає різницю). П1/П5 жалуються «навіщо тратив 50k».',
                'recommended_min'    => '1.10',
                'recommended_max'    => '1.25',
                'hard_min'           => '1.00',
                'hard_max'           => '2.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.blastfurnace.l3.craft_yield_multiplier',
                'category'           => 'buildings',
                'value_type'         => 'float',
                'value_float'        => 1.35,
                'default_value_text' => '1.35',
                'rationale_text'     => '+35% yield на L3 — крупна винагорода за фінальний upgrade BlastFurnace (75k gold + character_level≥12). L4-L10 наслідують через cascade (plateau, як у S11 Workshop). 1 char вже на L2 на проді отримає L2 ефект; майбутні L3+ — full bonus.',
                'effect_text'        => 'Множник quantity вихідних MetalFragments (та інших recipe з boost_building=BlastFurnace). BlastFurnace L3+ → qty_final = max(qty_base, round(qty_base × 1.35)). qty=10→14 (+4), qty=50→68 (+18), qty=100→135 (+35).',
                'above_effect_text'  => 'При 1.50+ — переплавка стає основним джерелом ресурсів, інші activity (gather/trade) знецінюються. Інфляція MetalFragments → деваль інших T2-T3 крафтів.',
                'below_effect_text'  => 'При 1.20 — L3 = +5% над L2 (1.15), плохо чувствуется як «крупний» апгрейд, П1/П5 жалуються на відсутність momentum.',
                'recommended_min'    => '1.25',
                'recommended_max'    => '1.50',
                'hard_min'           => '1.00',
                'hard_max'           => '3.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue; // idempotent
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'building.blastfurnace.l2.craft_yield_multiplier',
                'building.blastfurnace.l3.craft_yield_multiplier',
            ])
            ->delete();
    }
}
