<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Транспортная система (`docs/specs/transport-system/`, story transport-05) — три
 * hardcoded-константы цены одиночного шага (`MoveCharacterToDirectionAction`) уезжают
 * в GameSettings под `world.move.*` (категория `world`). Дефолты равны сегодняшним
 * hardcoded-значениям (`baseHealthCost=0.1`, `baseTiredCost=3.35`, danger-надбавка `+1.15`) —
 * до включения килсвитча `world.vehicle.enabled` и без активного транспорта арифметика шага
 * байт-идентична прежней (см. `SingleStepMoveCostTest`).
 *
 * Idempotent по `setting_key` (как `SeedVehicleGameSettings`). `game_settings` = KEEP
 * (WipeManifest не трогаем — новых таблиц/player-колонок эта миграция не создаёт).
 */
class SeedWorldMoveGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.move.health_cost_base',
                'value_type'         => 'float',
                'value_float'        => 0.1,
                'default_value_text' => '0.1',
                'rationale_text'     => 'Базовая цена ❤️ здоровья за один синхронный шаг по карте (move_dir_*). Значение перенесено 1:1 из hardcoded `MoveCharacterToDirectionAction::$baseHealthCost` — одиночный шаг мгновенен, транспорт на него не влияет вовсе (только danger-надбавка ниже).',
                'effect_text'        => 'MoveCharacterToDirectionAction::computeStepCost() читает это значение как базу здоровья ПЕРЕД danger-надбавкой; профиль транспорта здоровье не трогает никогда.',
                'above_effect_text'  => 'Выше 0.1 — каждый шаг заметнее выедает здоровье, игрок упирается в лечение чаще при том же темпе исследования.',
                'below_effect_text'  => 'Ниже 0.1 — здоровье почти не тратится при ходьбе, аптечки/еда теряют часть смысла как sink передвижения.',
                'recommended_min'    => '0.05',
                'recommended_max'    => '0.2',
                'hard_min'           => '0.0',
                'hard_max'           => '1.0',
            ],
            [
                'setting_key'        => 'world.move.tired_cost_base',
                'value_type'         => 'float',
                'value_float'        => 3.35,
                'default_value_text' => '3.35',
                'rationale_text'     => 'Базовая цена 💤 усталости за один синхронный шаг по карте (move_dir_*). Значение перенесено 1:1 из hardcoded `MoveCharacterToDirectionAction::$baseTiredCost`. Транспорт умножает эту базу через `tired_factor` профиля (нейтраль 1.0 = без изменений), новичок — через `EarlyProgressionService::moveCostFactor()` ×0.8, оба множителя применяются одним выражением без промежуточного округления.',
                'effect_text'        => 'MoveCharacterToDirectionAction::computeStepCost() читает это значение как базу усталости ДО применения `tired_factor` транспорта и `moveCostFactor` новичка.',
                'above_effect_text'  => 'Выше 3.35 — игрок упирается в потолок усталости быстрее, шагов до вынужденного отдыха/сна меньше.',
                'below_effect_text'  => 'Ниже 3.35 — усталость почти не мешает бесконечно ходить, теряется темп-регулятор передвижения.',
                'recommended_min'    => '2.0',
                'recommended_max'    => '5.0',
                'hard_min'           => '0.0',
                'hard_max'           => '10.0',
            ],
            [
                'setting_key'        => 'world.move.danger_tired_surcharge',
                'value_type'         => 'float',
                'value_float'        => 1.15,
                'default_value_text' => '1.15',
                'rationale_text'     => 'Надбавка к цене ❤️ здоровья шага, если целевая клетка опасного биома (`danger_level >= 8`). Значение перенесено 1:1 из hardcoded `+1.15` в `MoveCharacterToDirectionAction::handle()`. Название ключа исторически про усталость (контракт `plan.md`), но фактически надбавка ложится на здоровье — так было в коде до выноса, менять семантику эта story не вправе (байт-идентичность).',
                'effect_text'        => 'MoveCharacterToDirectionAction::computeStepCost() прибавляет это значение к здоровью, когда `$dangerBiome=true`.',
                'above_effect_text'  => 'Выше 1.15 — заход в опасный биом (danger_level>=8) заметно дороже по здоровью, игрок сильнее избегает такие клетки.',
                'below_effect_text'  => 'Ниже 1.15 — опасные биомы перестают ощутимо отличаться по цене от обычных, теряют часть risk/reward.',
                'recommended_min'    => '0.5',
                'recommended_max'    => '2.0',
                'hard_min'           => '0.0',
                'hard_max'           => '5.0',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $keys = [
            'world.move.health_cost_base',
            'world.move.tired_cost_base',
            'world.move.danger_tired_surcharge',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
