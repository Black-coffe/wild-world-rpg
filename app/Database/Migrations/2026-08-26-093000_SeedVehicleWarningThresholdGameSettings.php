<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ревью-находка (m1): `MarchingTaskHandler::vehicleWarningThresholdRatio()` читал
 * `world.vehicle.warning_threshold_ratio` через `gsFloat(..., 0.2)`, но ключ нигде не был
 * посеян — в админке его не существовало, значение жило только фолбэком в коде. Это
 * величина, видимая игроку («скоро сломается»), поэтому регистрируется в `GameSettings`
 * (ADR-024, `.claude/rules/balance.md`) с обязательными rationale/effect/above/below.
 *
 * Значение 0.2 совпадает с сегодняшним фолбэком — поведение не меняется, только источник
 * правды переезжает из кода в БД (код продолжает читать через `gsFloat` с тем же фолбэком —
 * `MarchingTaskHandler` в Files этой правки не входит и не тронут). Idempotent (по
 * `setting_key`).
 */
class SeedVehicleWarningThresholdGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $exists = $this->db->table('game_settings')
            ->where('setting_key', 'world.vehicle.warning_threshold_ratio')
            ->get()
            ->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert([
            'setting_key'        => 'world.vehicle.warning_threshold_ratio',
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 0.2,
            'value_int'          => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '0.2',
            'rationale_text'     => 'Порог доли оставшегося ресурса машины (заряды/выносливость), при котором игрок видит предупреждение «скоро сломается». 0.2 (20%) — действующий фолбэк в коде: заметный запас, чтобы успеть доехать до ремонта, но не настолько ранний, чтобы предупреждение превратилось в шум на каждой второй клетке.',
            'effect_text'        => 'MarchingTaskHandler::vehicleWarningThresholdRatio() (advanceOneCell) сравнивает текущий остаток заряда машины с этим порогом; при пересечении сверху вниз игроку показывается предупреждение об износе транспорта.',
            'above_effect_text'  => 'При 0.5+ предупреждение срабатывает почти сразу после старта похода — игрок видит его в большинстве шагов, сигнал теряет смысл (крик «волки» на каждом шагу).',
            'below_effect_text'  => 'При 0.05 предупреждение приходит в последний момент — часто позже, чем игрок успевает среагировать (доехать до базы/ремонта), машина ломается почти без предупреждения.',
            'recommended_min'    => '0.1',
            'recommended_max'    => '0.35',
            'hard_min'           => '0.01',
            'hard_max'           => '0.9',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'world.vehicle.warning_threshold_ratio')
            ->delete();
    }
}
