<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-085 — Склад как источник ёмкости хранения + мягкий бонус продажи.
 *
 * 5 GameSettings: 3× `building.warehouse.l<N>.storage_bonus_kg` (category=buildings,
 * cascade-бонус к weight-cap при наличии Склада) + `economy.sell.warehouse_bonus.enabled`
 * (killswitch, OFF) + `economy.sell.warehouse_bonus_pct` (default +10%).
 *
 * **Ship-time safe (DORMANT):** weight-cap killswitch `inventory.weight_cap.enabled`
 * остаётся false → storage_bonus_kg не enforced; `economy.sell.warehouse_bonus.enabled`
 * false → бонус продажи ×1.0. Нулевой эффект на живых игроков. Активация — осознанно
 * после balance-pass (ADR-085 Фаза 1b).
 *
 * Constitutional (ADR-024): rationale/effect/above/below NOT NULL. Idempotent.
 */
class ADR085SeedWarehouseStorageAndSellBonus extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.warehouse.l1.storage_bonus_kg',
                'category'           => 'buildings',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'rationale_text'     => 'Бонус ёмкости инвентаря (kg) за Склад L1. +200 при базовом cap L1=100 → втрое больше для владельца Склада. Склад = «хранение»: построил — носишь/хранишь больше. Cascade вниз до L1 (наличие Склада уже даёт бонус, не нужно качать).',
                'effect_text'        => 'WeightCapacityService::warehouseBonusKg() добавляется к cap в getRemainingCapacity/canAdd. Активно только при inventory.weight_cap.enabled=true.',
                'above_effect_text'  => 'При 1000: Склад снимает почти всякое давление веса в early-game → weight-cap становится косметикой для владельцев Склада.',
                'below_effect_text'  => 'При 0: Склад не влияет на ёмкость → теряется смысл «вместимости», возвращается фикция аудита.',
                'recommended_min'    => '100',
                'recommended_max'    => '500',
                'hard_min'           => '0',
                'hard_max'           => '100000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.warehouse.l2.storage_bonus_kg',
                'category'           => 'buildings',
                'value_type'         => 'int',
                'value_int'          => 400,
                'default_value_text' => '400',
                'rationale_text'     => 'Бонус ёмкости за Склад L2 (+400 kg). Удвоение L1-бонуса — ощутимая награда за апгрейд (50k золота). Контракт «каждый уровень Склада заметно расширяет хранилище».',
                'effect_text'        => 'WeightCapacityService::warehouseBonusKg() cascade: L2 Склад → +400 kg к cap. Активно при inventory.weight_cap.enabled=true.',
                'above_effect_text'  => 'При 1500: L2 даёт почти безлимит → L3-апгрейд теряет привлекательность.',
                'below_effect_text'  => 'При 200 (=L1): апгрейд L1→L2 не ощущается, «зачем платил 50k».',
                'recommended_min'    => '250',
                'recommended_max'    => '900',
                'hard_min'           => '0',
                'hard_max'           => '100000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'building.warehouse.l3.storage_bonus_kg',
                'category'           => 'buildings',
                'value_type'         => 'int',
                'value_int'          => 700,
                'default_value_text' => '700',
                'rationale_text'     => 'Бонус ёмкости за Склад L3 (+700 kg). Финальный апгрейд (75k+) → крупная награда. L4-L10 наследуют через cascade (плато, как у прочих зданий до ADR-042-интерполяции; для Склада плато приемлемо — хранилище не боевой стат).',
                'effect_text'        => 'WeightCapacityService::warehouseBonusKg() cascade: L3+ Склад → +700 kg к cap. Активно при inventory.weight_cap.enabled=true.',
                'above_effect_text'  => 'При 3000: эндгейм-хордеры (П8) копят без предела → инфляция складских запасов, обесценивает логистику (карго-дроны/база).',
                'below_effect_text'  => 'При 400 (=L2): L3-апгрейд бессмыслен.',
                'recommended_min'    => '500',
                'recommended_max'    => '1500',
                'hard_min'           => '0',
                'hard_max'           => '100000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'economy.sell.warehouse_bonus.enabled',
                'category'           => 'economy',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch мягкого бонуса цены продажи крафта владельцам Склада (ADR-085, вместо жёсткого SELL-гейта — тот player-hostile). false на ship → бонус ×1.0, 0 эффекта. Активация осознанно (бонус — pure plus, риск низкий, но синхронно с анонсом роли Склада).',
                'effect_text'        => 'WarehouseSellBonusService::sellPriceMultiplier() в SellCraftConfirmAction. false → ×1.0 всем.',
                'above_effect_text'  => 'true: владельцы Склада продают крафт дороже на warehouse_bonus_pct (стимул строить Склад на стороне продажи).',
                'below_effect_text'  => 'false: продажа одинакова всем (текущее поведение).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'economy.sell.warehouse_bonus_pct',
                'category'           => 'economy',
                'value_type'         => 'float',
                'value_float'        => 0.10,
                'default_value_text' => '0.10',
                'rationale_text'     => '+10% к цене продажи крафта владельцам Склада. Умеренный стимул: ощутимо, но не делает Склад обязательным для продажи (продажа без Склада остаётся валидной). Клампится сервисом в [0,1] (макс +100%).',
                'effect_text'        => 'WarehouseSellBonusService::sellPriceMultiplier() = 1 + pct, применяется ПОСЛЕ карма-формулы и клампа в SellCraftConfirmAction. Активно при economy.sell.warehouse_bonus.enabled=true.',
                'above_effect_text'  => 'При 0.50 (+50%): Склад почти обязателен для продажи → де-факто soft-гейт, давит игроков без Склада.',
                'below_effect_text'  => 'При 0.02 (+2%): бонус незаметен, не мотивирует строить Склад.',
                'recommended_min'    => '0.05',
                'recommended_max'    => '0.25',
                'hard_min'           => '0.00',
                'hard_max'           => '1.00',
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
                'building.warehouse.l1.storage_bonus_kg',
                'building.warehouse.l2.storage_bonus_kg',
                'building.warehouse.l3.storage_bonus_kg',
                'economy.sell.warehouse_bonus.enabled',
                'economy.sell.warehouse_bonus_pct',
            ])
            ->delete();
    }
}
