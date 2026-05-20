<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S20 (v0.51.202) — seed gather-bonus GameSettings для 2 «оживляемых» T3 tools
 * (constitutional admin-tunable balance, ADR-024; ROADMAP-CRAFT §7 Фаза 4 5/5).
 *
 * **Контекст:** Sapper Shovel / Golden Hoe лежат в crafted_items (type='tool'),
 * owned игроками, но НЕ в `ToolManager::getToolsForResource()` map → в gather
 * не дают бонуса (мёртвые, как S19-orphan drugs). S20 оживляет их через
 * **GameSettings overlay** в ToolManager: для их resource-set'а бонус читается
 * из этих ключей (admin-tunable), legacy hardcoded map не трогается = 0 регрессии.
 *
 * DiamondPickaxe НЕ здесь — он уже в legacy map с per-resource бонусами
 * (1.8/2.2/0.6…), работает; S20 добавляет ему только рецепт.
 *
 * `gather_bonus` — единый множитель прибавки для всех ресурсов из resource-set'а
 * инструмента (resource-eligibility = data shape в ToolManager const; величина
 * бонуса = balance-параметр здесь). Итоговый yield = base × (1 + bonus).
 *
 * Категория `resources` (Ресурсы и редкость) — влияет на добычу ресурсов.
 *
 * Constitutional rule: rationale/effect/above/below NOT NULL. Idempotent.
 */
class S20SeedToolBonusGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tools.sapper_shovel.gather_bonus',
                'value_float'        => 0.70,
                'default_value_text' => '0.70',
                'rationale_text'     => '+70% к добыче копаемых ресурсов (глина/песок/ил/травы/мхи/снег/береговая растительность). Премиальная T3-лопата заметно выше IronShovel (+10..30%), но ниже DiamondPickaxe-tier (+180..220%). Делает T3-крафт лопаты осмысленным апгрейдом для копателей.',
                'effect_text'        => 'ToolManager::getToolsForResource() overlay: добавляет Sapper Shovel с этим бонусом ко всем ресурсам её resource-set. pickBestTool выберет её, если бонус выше прочих имеющихся инструментов. Итог: base × (1 + bonus).',
                'above_effect_text'  => 'При 1.5 — лопата перебивает DiamondPickaxe по копаемым, ломает иерархию инструментов и инфляция ресурсов у копателей.',
                'below_effect_text'  => 'При 0.30 — flat с IronShovel, T3-крафт лопаты теряет смысл (не апгрейд).',
            ],
            [
                'setting_key'        => 'tools.golden_hoe.gather_bonus',
                'value_float'        => 0.60,
                'default_value_text' => '0.60',
                'rationale_text'     => '+60% к фермерской добыче (овощи/травы/мхи/фрукты). Премиальная T3-мотыга выше обычной Hoe (+9..30%). Усиливает фермеров без перекоса в сторону копателей/шахтёров.',
                'effect_text'        => 'ToolManager::getToolsForResource() overlay: добавляет Golden Hoe с этим бонусом к её resource-set. Итог: base × (1 + bonus).',
                'above_effect_text'  => 'При 1.2 — мотыга даёт слишком много фермерских ресурсов, перекос экономики еды/трав.',
                'below_effect_text'  => 'При 0.30 — flat с обычной Hoe, T3-крафт мотыги теряет смысл.',
            ],
        ];

        $shared = [
            'category'        => 'resources',
            'value_type'      => 'float',
            'recommended_min' => '0.3',
            'recommended_max' => '1.5',
            'hard_min'        => '0',
            'hard_max'        => '5',
            'created_at'      => $now,
            'updated_at'      => $now,
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
                'tools.sapper_shovel.gather_bonus',
                'tools.golden_hoe.gather_bonus',
            ])
            ->delete();
    }
}
