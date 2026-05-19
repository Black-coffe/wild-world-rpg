<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S18 (v0.51.200) — seed 4 GameSettings keys для T3 armor craft durations
 * (ADR-026 reusable pattern, ROADMAP-CRAFT §7 Фаза 4 3/5).
 *
 * Каждое T3 armor — admin-tunable длительность через `tier3.armor.<key>.craft_duration_hours`:
 *   - tactical_armor_suit     = 2h  (L16 Epic body)
 *   - exoskeleton_strekoza    = 2h  (L16 Epic body, light)
 *   - titan_power_armor       = 4h  (L20 Legendary heavy)
 *   - tesla_shard_armor       = 6h  (L25 Legendary top-tier electric)
 *
 * Wire-up: GenericCraftActionStart::calculateCraftingDuration читает ключ
 * через recipe.duration_override_setting_key (ADR-026 wire-up). Fallback на
 * tasks.min/max_duration если ключ отсутствует.
 *
 * Constitutional rule (CLAUDE.md §🎛️, ADR-024): rationale_text /
 * effect_text / above_effect_text / below_effect_text NOT NULL.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class S18SeedT3ArmorGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.armor.tactical_armor_suit.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для L16 Epic body — entry-level T3 armor (parity с GaussPistol L16 weapon). Самая «mid-balanced» броня Epic-tier (22 armor, 12 вес). Делает T3 armor доступным сразу после построения верстака.',
                'effect_text'        => 'Длительность task craftTacticalArmorSuit (override tasks.min/max_duration). GenericCraftActionStart::calculateCraftingDuration читает через recipe.duration_override_setting_key.',
                'above_effect_text'  => 'При 4h — entry-level T3 armor теряет accessibility; чары L16-L17 ждут долго, выберут только weapon-craft.',
                'below_effect_text'  => 'При 1h — armor превращается в casual-fast, обесценивает loot existing L16-L18 armor.',
            ],
            [
                'setting_key'        => 'tier3.armor.exoskeleton_strekoza.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для L16 Epic light — parity с TacticalArmorSuit (тот же level, Epic-tier). Lightweight option (10 armor, 9 вес) — alternative для agile builds. Crafting parity = баланс выбора.',
                'effect_text'        => 'Длительность task craftExoskeletonStrekoza (override tasks.min/max_duration). См. wire-up в Tactical-описании.',
                'above_effect_text'  => 'При 4h — light option становится более expensive чем Tactical, no reason выбирать.',
                'below_effect_text'  => 'При 1h — flat с тyranny "always pick light"; для эспер крафта break tradeoff.',
            ],
            [
                'setting_key'        => 'tier3.armor.titan_power_armor.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => '4 часа для L20 Legendary heavy — parity с Ion/Flame L20 weapons. Самая тяжёлая броня в crafting pool (30 armor, 25 вес). 4h = «вечерний commit» для heavy build.',
                'effect_text'        => 'Длительность task craftTitanPowerArmor (override tasks.min/max_duration). См. wire-up в Tactical-описании.',
                'above_effect_text'  => 'При 8h — overnight commit; Titan становится «sleep-on-it» — теряет immediacy reward за L20 achievement.',
                'below_effect_text'  => 'При 2h — flat с Epic-tier (Tactical), нарушает rarity-time progression.',
            ],
            [
                'setting_key'        => 'tier3.armor.tesla_shard_armor.craft_duration_hours',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => '6 часов для L25 Legendary top-tier electric — aspirational top-craft armor. Parity с ExoRailgunBehemoth L24 weapon. Tesla-аура (27 armor, 20 вес) — премиум электрическая защита для endgame чаров.',
                'effect_text'        => 'Длительность task craftTeslaShardArmor (override tasks.min/max_duration). См. wire-up в Tactical-описании.',
                'above_effect_text'  => 'При 12h — overnight + утренний commit; L25 чары frustration после 35000g + редкие ресурсы.',
                'below_effect_text'  => 'При 4h — flat с L20 Legendary (Titan), теряется premium за L25 tier.',
            ],
        ];

        $shared = [
            'category'           => 'craft',
            'value_type'         => 'int',
            'recommended_min'    => '2',
            'recommended_max'    => '12',
            'hard_min'           => '1',
            'hard_max'           => '48',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'tier3.armor.tactical_armor_suit.craft_duration_hours',
                'tier3.armor.exoskeleton_strekoza.craft_duration_hours',
                'tier3.armor.titan_power_armor.craft_duration_hours',
                'tier3.armor.tesla_shard_armor.craft_duration_hours',
            ])
            ->delete();
    }
}
