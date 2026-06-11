<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-115 (E15) — настройки спавна элитных NPC.
 *
 * 5 ключей категории world. killswitch enabled default OFF → EliteSpawnHandler no-op (dormant).
 * Seed game_settings (KEEP). Idempotent. ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr115ElitesGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'world.elites.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch спавна элитных NPC (E15, ADR-113 гейт L25). Элиты (L30/40/50) — осмысленный PvE-вызов между рейдерами и боссами, спавнятся в средней полосе Y300-600, дропают «Древние реликвии» (компонент грандмастер-крафта E14). default=false → EliteSpawnHandler не спавнит (dormant) до активации после Tier-3.',
                'effect_text'        => 'ON: EliteSpawnHandler (everyMinute) поддерживает population_each элиток каждого тира в поясе Y[y_min,y_max]; убитая элита респавнится в НОВОЙ клетке (анти-фарм). OFF: элиты не спавнятся.',
                'above_effect_text'  => 'true: ветераны получают PvE-цель + источник реликвий для E14. Включать после Tier-3 и проверки сложности/плотности.',
                'below_effect_text'  => 'false: элит нет (dormant). Emergency-off при перекосе сложности/фарм-абузе.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.elites.population_each',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Сколько живых экземпляров КАЖДОГО тира элиты (L30/40/50) поддерживается в поясе. 3×3=9 элит на ~90k клеток пояса — редкая встреча, не ковёр (как боссы population_each).',
                'effect_text'        => 'EliteSpawnHandler доливает до этого числа живых на тир; убитые респавнятся.',
                'above_effect_text'  => 'Выше: чаще встречи, больше реликвий → следить за инфляцией компонента/фармом.',
                'below_effect_text'  => 'Ниже: реже, элиты — событие.',
                'recommended_min'    => '1', 'recommended_max' => '10', 'hard_min' => '0', 'hard_max' => '100',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.elites.y_min',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 300,
                'default_value_text' => '300',
                'rationale_text'     => 'Северная граница пояса элиток (включительно). Y300-600 — средняя полоса («вторая родина» подросшего игрока, ADR-113 E16). Севернее (Y<300) — зона боссов/рейдеров.',
                'effect_text'        => 'Элиты спавнятся в клетках coordinate_y ≥ y_min.',
                'above_effect_text'  => 'Выше: пояс сдвигается южнее.',
                'below_effect_text'  => 'Ниже: пояс залезает в опасный север.',
                'recommended_min'    => '100', 'recommended_max' => '500', 'hard_min' => '0', 'hard_max' => '999',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.elites.y_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 600,
                'default_value_text' => '600',
                'rationale_text'     => 'Южная граница пояса элиток (исключительно). Y300-600. Южнее (Y>600) — зона подростков/новичков, элиты туда не лезут. Принцип «север ценнее»: тир L50 спавнится в северной трети пояса (хардкод-смещение в EliteSpawnHandler).',
                'effect_text'        => 'Элиты спавнятся в клетках coordinate_y < y_max (с тир-смещением к северу).',
                'above_effect_text'  => 'Выше: пояс шире/южнее (ближе к новичкам — осторожно).',
                'below_effect_text'  => 'Ниже: пояс уже.',
                'recommended_min'    => '400', 'recommended_max' => '750', 'hard_min' => '1', 'hard_max' => '1000',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'world.elites.max_adjust_per_tick',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Бюджет спавн-операций EliteSpawnHandler за тик (анти-нагрузка ORDER BY RAND). 30 хватает добить дефицит после активации за несколько тиков.',
                'effect_text'        => 'Не более N вставок элит за один everyMinute-тик.',
                'above_effect_text'  => 'Выше: быстрее заполнение, выше нагрузка RAND-выборок.',
                'below_effect_text'  => 'Ниже: плавнее, дольше первичное заполнение.',
                'recommended_min'    => '5', 'recommended_max' => '100', 'hard_min' => '1', 'hard_max' => '500',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            if ($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults() === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.elites.enabled', 'world.elites.population_each',
            'world.elites.y_min', 'world.elites.y_max', 'world.elites.max_adjust_per_tick',
        ])->delete();
    }
}
