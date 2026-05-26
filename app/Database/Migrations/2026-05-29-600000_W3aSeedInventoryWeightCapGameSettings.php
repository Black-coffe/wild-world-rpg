<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W3a (ADR-059) — 3 GameSettings `inventory.weight_cap.*` (category=resources).
 *
 * Killswitch + scaling formula: enabled=false → механика выключена, gather
 * проходит без cap-check (legacy behaviour). Когда enabled=true → `cap(level) =
 * l1_base + (level - 1) × per_level`. Default scaling: L1=100kg / L100=400kg.
 *
 * **Ship-time safe:** default enabled=false → существующие игроки 0 регрессии.
 * Активируем через admin live-tune (ADR-024) после balance-pass.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below
 * NOT NULL. Idempotent: пропускает существующие setting_key.
 */
class W3aSeedInventoryWeightCapGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'inventory.weight_cap.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch механики weight-cap (W3a ADR-059 foundation для Cargo drone W3b). false на ship — gather проходит без cap-check, существующие игроки 0 регрессии, П2/П9 retention не страдает. Включаем через admin live-tune после balance-pass (W3b или W8 Inventory revamp). До включения характеристика `characters.weight_capacity` хранится (default 9999), но не enforced.',
                'effect_text'        => 'WeightCapacityService::isEnabled() (W3a build). Контролирует: gate на gather (GatherResultPersister patch), видимость UI «Склад» на базе, gate на cargo-drone launch (W3b).',
                'above_effect_text'  => 'true: weight-cap включён. Gather блокируется при overflow, игрок видит UI «Склад» и cargo-drone становится полезным. Требует балансной формулы (см. l1_base + per_level).',
                'below_effect_text'  => 'false: фича выключена. Существующая legacy-механика unrestricted gather. Cargo drone становится novelty без real-use-case (но всё ещё работает как delivery, просто redundant с возвратом пешком).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'inventory.weight_cap.l1_base',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Базовый weight_capacity для персонажа L1 (kg). Default 100 = P2-friendly стартовая нагрузка. Per-resource weight в `character_resources` обычно 0.05–5 kg; 100 kg = ~50-100 unit базовых ресурсов до cap. Формула суммарного cap: `cap(level) = l1_base + (level - 1) × per_level`.',
                'effect_text'        => 'WeightCapacityService::computeCapacity(level) (W3a build). Применяется при инициализации новых чаров (characters.weight_capacity = computeCapacity(1) = 100) и при level-up bump-event.',
                'above_effect_text'  => 'При 500: L1 могут таскать 500 kg → weight-cap почти неощутим в early-game, фича становится BUILT-BUT-DEAD для новичков (только эндгейм видит).',
                'below_effect_text'  => 'При 20: L1 моментально упирается в cap → новичок не понимает почему gather блокируется. П1/П9 confusion → retention drop. Слишком rough для onboarding.',
                'recommended_min'    => '50',
                'recommended_max'    => '200',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'inventory.weight_cap.per_level',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Прирост weight_capacity за каждый level персонажа (kg). Default 3 → L100 = 100 + 99×3 = 397 kg (~4× L1). Медленный grow keeping mid/late game под weight-pressure. Резолюция Q3 ADR-059: scaling формула вместо building-buffs (W8 inventory revamp может ввести Warehouse-buff поверх).',
                'effect_text'        => 'WeightCapacityService::computeCapacity(level). При level-up — пересчёт `characters.weight_capacity` через bump-event.',
                'above_effect_text'  => 'При 10: L100 = 100 + 990 = 1090 kg → weight-cap почти невидим в эндгейме. Фича становится early-game-only friction.',
                'below_effect_text'  => 'При 1: L100 = 199 kg → почти статичный cap, эндгейм-игрок чувствует «не вырос». Прогрессия отсутствует.',
                'recommended_min'    => '2',
                'recommended_max'    => '10',
                'hard_min'           => '0',
                'hard_max'           => '100',
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
                'inventory.weight_cap.enabled',
                'inventory.weight_cap.l1_base',
                'inventory.weight_cap.per_level',
            ])
            ->delete();
    }
}
