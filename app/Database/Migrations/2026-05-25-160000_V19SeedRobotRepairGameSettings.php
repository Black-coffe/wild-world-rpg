<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V19 (ADR-050) — GameSettings для ремонта роботов (category=resources).
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V19SeedRobotRepairGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'robots.repair.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch ремонта роботов (восстановление durability за ресурсы/золото). true — кнопка 🔧 Ремонт доступна в Мастерской робототехники. false — ремонт скрыт/заблокирован (как до V19). Закрывает обещание здания «создавать И ремонтировать роботов».',
                'effect_text'        => 'RobotRepairService::enabled. false → кнопка ремонта не рисуется на активаторе, RobotRepairConfirmAction отклоняет действие.',
                'above_effect_text'  => 'true: игрок чинит частично израсходованного робота вместо перекрафта (sink + maintenance-loop).',
                'below_effect_text'  => 'false: мгновенный откат слоя ремонта без редеплоя (роботы снова чисто расходники — крафтить заново).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'robots.repair.cost_fraction',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.6,
                'default_value_text' => '0.6',
                'rationale_text'     => 'Доля от пропорциональной стоимости крафта (золото + ресурсы), которую игрок платит за ПОЛНОЕ восстановление durability робота. 0.6 = ремонт стоит 60% от перекрафта той же durability — заметно дешевле постройки заново, поэтому ремонт = выгодный maintenance-loop, но всё равно реальный sink (золото+ресурсы каждый ремонт). Частичное восстановление — пропорционально (× restored/base).',
                'effect_text'        => 'RobotRepairService::computeCost: за золото и каждый ресурс рецепта cost = ceil(amount × cost_fraction × restored/base). Компоненты (под-детали) не берутся.',
                'above_effect_text'  => 'При 1.0 ремонт = по цене перекрафта (за durability) → нет смысла чинить вместо постройки нового (но без resource-затрат на компоненты — всё равно чуть выгоднее). При >1.0 ремонт дороже перекрафта.',
                'below_effect_text'  => 'При 0.2 ремонт почти бесплатный → роботы фактически вечны за копейки, gold/resource sink схлопывается, экономика эндгейма теряет отток.',
                'recommended_min'    => '0.40',
                'recommended_max'    => '0.90',
                'hard_min'           => '0.10',
                'hard_max'           => '2.00',
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
            ->whereIn('setting_key', ['robots.repair.enabled', 'robots.repair.cost_fraction'])
            ->delete();
    }
}
