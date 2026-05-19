<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S17 (v0.51.199) — seed 5 GameSettings keys для T3 weapon craft durations
 * (ADR-026 reusable pattern, ROADMAP-CRAFT §7 Фаза 4).
 *
 * Каждое T3 оружие — admin-tunable длительность через `tier3.weapons.<key>.craft_duration_hours`:
 *   - gauss_pistol           = 2h  (L16 Epic Ranged)
 *   - rail_carbine_vikhr     = 3h  (L18 Epic Ranged)
 *   - ion_destabilizer       = 4h  (L20 Legendary Energy)
 *   - flamethrower_aid       = 4h  (L20 Legendary Ranged)
 *   - exo_railgun_behemoth   = 6h  (L24 Legendary Ranged)
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
class S17SeedT3WeaponGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.weapons.gauss_pistol.craft_duration_hours',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => '2 часа для L16 Epic ranged — entry-level T3 craft. Соразмерно с T2 максимальным (CrossbowMk1 = 20 min) × ~6, ниже чем сам T3 верстак (8h). Игрок видит «крафт ощутимый, но не суточный» — стимулирует первый T3 craft как proof-of-concept.',
                'effect_text'        => 'Длительность task craftGaussPistol (override tasks.min/max_duration). GenericCraftActionStart::calculateCraftingDuration читает через recipe.duration_override_setting_key, конвертирует в минуты (N*60), использует как min=max.',
                'above_effect_text'  => 'При 4h — entry-level T3 теряет accessibility, чары L16-L17 ждут долго; spike отказов от T3-крафта в первые 2 недели после релиза.',
                'below_effect_text'  => 'При 1h — T3 craft становится casual-fast, обесценивает loot-pool существующих L16-L18 weapons (Gauss + RailCarbine как loot теряют ценность).',
            ],
            [
                'setting_key'        => 'tier3.weapons.rail_carbine_vikhr.craft_duration_hours',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => '3 часа для L18 Epic ranged — mid-T3 craft. Линейная progression от Gauss (2h) к Ion/Flame (4h). L18 weapons часто loot-fallback для тех кто не дошёл до L20+ Legendary — T3 рецепт даёт альтернативу через craft.',
                'effect_text'        => 'Длительность task craftRailCarbineVikhr (override tasks.min/max_duration). См. wire-up в Gauss-описании.',
                'above_effect_text'  => 'При 6h — линейная progression ломается (jump до уровня Ion/Flame); L18 чары предпочтут ждать loot.',
                'below_effect_text'  => 'При 2h — flat с Gauss, теряется sense of progression (зачем L18 weapon если он крафтится так же как L16?).',
            ],
            [
                'setting_key'        => 'tier3.weapons.ion_destabilizer.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => '4 часа для L20 Legendary Energy — сильнейший T3 entry-level Legendary. Совпадает с release-уровнем T3 верстака (gate=L20). Игрок построил верстак → может сразу крафтить Ion/Flame.',
                'effect_text'        => 'Длительность task craftIonDestabilizer (override tasks.min/max_duration). См. wire-up в Gauss-описании.',
                'above_effect_text'  => 'При 8h — L20 Legendary становится «overnight commit» как сам верстак; снижает immediacy reward за достижение L20.',
                'below_effect_text'  => 'При 2h — Legendary крафтится быстрее Epic (L18 RailCarbine = 3h), нарушает rarity-time progression.',
            ],
            [
                'setting_key'        => 'tier3.weapons.flamethrower_aid.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => '4 часа для L20 Legendary Ranged — parity с Ion (тот же уровень, та же Legendary rarity, разные damage_type). Игрок выбирает между Energy (Ion) и Ranged-burst (Flamethrower) — оба равны по cost-time.',
                'effect_text'        => 'Длительность task craftFlamethrowerAid (override tasks.min/max_duration). См. wire-up в Gauss-описании.',
                'above_effect_text'  => 'При 6h — Flamethrower становится более expensive чем Ion (тот же level/rarity), игроки выберут только Ion.',
                'below_effect_text'  => 'При 2h — slash к Epic (RailCarbine 3h), parity ломается.',
            ],
            [
                'setting_key'        => 'tier3.weapons.exo_railgun_behemoth.craft_duration_hours',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => '6 часов для L24 Legendary Ranged — aspirational top-craft. Между L20 Legendary (4h) и L26 Hydra (не входит в S17 pool). 40 damage — самое сильное T3 craftable, время отражает investment.',
                'effect_text'        => 'Длительность task craftExoRailgunBehemoth (override tasks.min/max_duration). См. wire-up в Gauss-описании.',
                'above_effect_text'  => 'При 12h — overnight + утренний commit; чары L24 ждут полные сутки, frustration после уже 50000g + редкие ресурсы.',
                'below_effect_text'  => 'При 4h — flat с L20 Legendary, теряется premium за L24+ tier.',
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
                'tier3.weapons.gauss_pistol.craft_duration_hours',
                'tier3.weapons.rail_carbine_vikhr.craft_duration_hours',
                'tier3.weapons.ion_destabilizer.craft_duration_hours',
                'tier3.weapons.flamethrower_aid.craft_duration_hours',
                'tier3.weapons.exo_railgun_behemoth.craft_duration_hours',
            ])
            ->delete();
    }
}
