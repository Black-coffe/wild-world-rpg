<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V23 (ADR-055) — GameSettings для NPC-мастера на базе: мгновенный
 * gold-only ремонт tools как альтернатива S5 (ресурсы + 15 мин).
 *
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V23SeedNpcRepairGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'npc.repair.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch NPC-мастера на базе: мгновенный gold-only ремонт tools параллельно S5 (ресурсы+15мин). true — кнопка «🛠 NPC-мастер» доступна рядом с обычным ремонтом. false — кнопка скрыта, action отклоняется (S5 не задет). Закрывает gold-sink для эндгейма (P7/P8 — 68M накопленного золота, V21 dashboard).',
                'effect_text'        => 'NpcRepairService::enabled. false → кнопка не рисуется в RepairToolsListAction/RepairCraftedItemAction, NpcRepairAction отклоняет действие. S5 остаётся работать как есть (независимые слои).',
                'above_effect_text'  => 'true: альтернативный sink. Эндгейм-игроки тратят золото мгновенно, новички продолжают чинить ресурсами (gold у них дороже). Активный gold-отток из экономики.',
                'below_effect_text'  => 'false: gold-sink пропадает, эндгейм-золото копится без оттока. S5 продолжает работать.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'npc.repair.markup',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 1.5,
                'default_value_text' => '1.5',
                'rationale_text'     => 'Наценка NPC-мастера сверх рыночной стоимости ресурсов для S5-ремонта. Базовая формула: gold = ceil(sum(template_qty × repair.cost_fraction × resources.price) × markup). 1.5 = NPC берёт +50% за услугу мгновенного ремонта. Tools-крафт уровня L5-15 у новичков обходится в 50-300g (необременительно), эндгейм-tools (Алмазная кирка, Золотая мотыга) — в 2-8k gold за ремонт, что укладывается в P7/P8 балансы.',
                'effect_text'        => 'NpcRepairService::markup. Используется в computeGoldCost: gold = ceil(Σ(qty[i] × repair.cost_fraction × price[i]) × markup).',
                'above_effect_text'  => 'При 2.0 NPC ремонт дороже на +100% сверх рыночной стоимости — П3-П6 предпочитают S5 (ждать 15 мин дешевле); реальный sink только у П7/П8. При 3.0+ ремонт почти не используется — sink схлопывается.',
                'below_effect_text'  => 'При 1.0 NPC ремонт = чистая конвертация ресурсов в gold по market price без наценки — но мгновенно. Игроки с gold переходят с S5 полностью (S5 становится никому не нужен у кого есть gold). При <1.0 NPC дешевле перепокупки ресурсов — экономический эксплойт.',
                'recommended_min'    => '1.20',
                'recommended_max'    => '2.50',
                'hard_min'           => '1.00',
                'hard_max'           => '5.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'npc.repair.cost_fraction',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Доля template-ресурсов рецепта, используемая в расчёте gold-цены NPC ремонта. 0.5 = NPC учитывает 50% объёма ресурсов рецепта (зеркало S5: repair.cost_fraction=0.5). Этот ключ — изолированный счётчик NPC: можно тюнить независимо от S5 (например, если S5 уйдёт на 0.4 для буста новичков, NPC может остаться на 0.5 — не двигая эндгейм-sink).',
                'effect_text'        => 'NpcRepairService::costFraction. Используется в computeGoldCost: gold = ceil(Σ(qty[i] × cost_fraction × price[i]) × markup).',
                'above_effect_text'  => 'При 1.0 NPC учитывает 100% template-ресурсов — gold-цена удваивается, sink сильнее. П7/П8 продолжают платить (большие запасы), П3-П6 уходят в S5.',
                'below_effect_text'  => 'При 0.2 gold-цена NPC падает в 2.5 раза → ремонт почти бесплатный → sink схлопывается. Поток gold-репair бесконтрольно растёт.',
                'recommended_min'    => '0.30',
                'recommended_max'    => '0.80',
                'hard_min'           => '0.10',
                'hard_max'           => '2.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'npc.repair.min_cost_gold',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Минимальная gold-цена NPC ремонта (защита от 0-cost при низких resources.price). Если расчётная цена ниже — берётся min. 10g — символическая плата за услугу, не обременяет новичков, но исключает эксплойт «бесплатный ремонт через дешёвые ресурсы».',
                'effect_text'        => 'NpcRepairService::computeGoldCost: после расчёта применяется max(min_cost_gold, computed). Tools с template из дешёвых ресурсов (Дерево/Камень price=1-5g) всё равно стоят min.',
                'above_effect_text'  => 'При 100g даже базовые tools на низкоранговых ресурсах стоят 100g — П1-П2 чувствительно (стартовый gold ограничен).',
                'below_effect_text'  => 'При 0 базовые tools можно ремонтировать почти бесплатно — sink схлопывается на низком тире.',
                'recommended_min'    => '5',
                'recommended_max'    => '50',
                'hard_min'           => '0',
                'hard_max'           => '1000',
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
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'npc.repair.enabled',
                'npc.repair.markup',
                'npc.repair.cost_fraction',
                'npc.repair.min_cost_gold',
            ])
            ->delete();
    }
}
