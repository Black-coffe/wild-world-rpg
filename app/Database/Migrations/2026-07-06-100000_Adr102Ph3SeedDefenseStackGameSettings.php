<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-102 Ф3 — стак обороны по количеству структур на базе (amount).
 * 3 admin-tunable GameSettings (ADR-024). category=combat. Idempotent.
 * DORMANT-first: defense.stack.enabled=0 по умолчанию → defenseStackFactor()=1.0
 * для любого amount → byte-identical (амортизация прежнего поведения).
 *
 *  - defense.stack.enabled: killswitch. OFF → стак не действует (amount игнорируется
 *    в DefenseStructureService, как было). Активация — после balance-pass (ретроактивно
 *    усилит базы, где уже настроено ≥2 одинаковых оборонных структур → анонс игрокам).
 *  - defense.stack.diminish_ratio: r геометрического ряда factor=(1−r^amount)/(1−r).
 *  - defense.stack.max_factor: жёсткий потолок множителя стака (страховка поверх ряда).
 */
class Adr102Ph3SeedDefenseStackGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'defense.stack.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Killswitch стака обороны (ADR-102 Ф3). OFF (0) → количество одинаковых оборонных структур на базе (amount) НЕ усиливает эффект — поведение как до фичи (byte-identical). ON (1) → стена/ограда складываются по количеству с diminishing returns (вышка НЕ стакает — presence). DORMANT по умолчанию: активация ретроактивно усилит базы, где уже стоит ≥2 одинаковых структуры → включать после balance-pass + анонса.',
                'effect_text'        => 'DefenseStructureService::defenseStackFactor умножает per-structure вклад стены (% снижения урона) и ограды (контрурон/раунд) на множитель стака. Глобальный cap defense.total_damage_reduction_max_percent (40%) применяется ПОСЛЕ — реальный потолок обороны не меняется.',
                'above_effect_text'  => 'ON: базы с несколькими стенами/оградами становятся крепче (до cap 40% по снижению урона; контрурон растёт по diminishing). Рейд таких баз дольше — перекос к defender, если переборщить с diminish_ratio/max_factor.',
                'below_effect_text'  => 'OFF: вторая+ оборонная структура на базе даёт +0 к эффекту (только amount-счётчик) — «стак» выключен.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'defense.stack.diminish_ratio',
                'value_type'         => 'float',
                'value_float'        => 0.6,
                'default_value_text' => '0.6',
                'rationale_text'     => 'Коэффициент r геометрического ряда стака: factor(amount) = (1 − r^amount)/(1 − r). При r=0.6: amount 1→×1.0, 2→×1.6, 3→×1.96, 4→×2.18, ∞→1/(1−r)=×2.5. Каждая следующая структура даёт 60% прироста предыдущей — осмысленный выбор «вглубь», а не бездумный спам.',
                'effect_text'        => 'Чем выше r — тем сильнее эффект каждой доп-структуры (медленнее убывает). Применяется к стене и ограде; вышка не стакает.',
                'above_effect_text'  => 'При 0.85+ стак почти линейный → много структур = почти ×N, риск непробиваемых баз (упор в cap 40% быстро) и обесценивания одиночной обороны.',
                'below_effect_text'  => 'При 0.2 вторая структура даёт лишь +20%, третья почти ничего → стак почти не ощущается, строить >1 невыгодно.',
                'recommended_min'    => '0.4',
                'recommended_max'    => '0.75',
                'hard_min'           => '0',
                'hard_max'           => '0.99',
            ],
            [
                'setting_key'        => 'defense.stack.max_factor',
                'value_type'         => 'float',
                'value_float'        => 2.5,
                'default_value_text' => '2.5',
                'rationale_text'     => 'Жёсткий потолок множителя стака (страховка поверх геометрического ряда). При r=0.6 ряд сам сходится к 2.5, так что 2.5 ≈ естественный предел; левер на случай высокого diminish_ratio или защиты от экстремального amount.',
                'effect_text'        => 'defenseStackFactor() клампится этим значением. Реальный потолок снижения урона всё равно — cap 40%; max_factor ограничивает контрурон ограды и поведение при больших amount.',
                'above_effect_text'  => 'При 4.0+ потолок не достигается рядом (r=0.6) → лишний левер без эффекта, но при высоком diminish_ratio позволит очень большой стак.',
                'below_effect_text'  => 'При 1.2 стак почти отключён (макс +20% от количества) даже при включённом killswitch.',
                'recommended_min'    => '1.5',
                'recommended_max'    => '3.0',
                'hard_min'           => '1',
                'hard_max'           => '10',
            ],
        ];

        $defaults = [
            'category'        => 'combat',
            'value_int'       => null,
            'value_float'     => null,
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'defense.stack.enabled',
            'defense.stack.diminish_ratio',
            'defense.stack.max_factor',
        ])->delete();
    }
}
