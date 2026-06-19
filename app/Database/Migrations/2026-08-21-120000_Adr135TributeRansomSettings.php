<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 Ф3 — GameSettings выкупа «Трофейной подати» (admin-tunable, ADR-024).
 *
 * 3 ключа `tribute.ransom_*` (category combat). Выкуп = gold-burn (золото СГОРАЕТ, не хозяину):
 * cost = clamp(base + floor(total_collected*k), base, hard_cap). hard_cap (анти-P2W) не даёт
 * «диких сумм» из исходной идеи. Dormant вместе с tribute.enabled. Idempotent (по setting_key).
 */
class Adr135TributeRansomSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tribute.ransom_base',
                'value_type'         => 'int',
                'value_int'          => 2000,
                'default_value_text' => '2000',
                'rationale_text'     => 'Базовая (минимальная) стоимость выкупа из-под подати в золоте. 2000 ≈ 2× стартовый капитал — ощутимо, но достижимо обычным фармом за пару дней (анти-«дикие суммы»/P2W).',
                'effect_text'        => 'cost = clamp(base + floor(total_collected*k), base, hard_cap). Золото при выкупе СГОРАЕТ (gold-sink), не уходит хозяину.',
                'above_effect_text'  => 'При 20000+ выкуп недостижим бедному → застревание (провал П9 anti-P2W); остаётся только реванш/таймер.',
                'below_effect_text'  => 'При 100 выкуп тривиален → подать не ощущается, статус без последствий.',
                'recommended_min'    => '1000',
                'recommended_max'    => '5000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'tribute.ransom_per_collected_k',
                'value_type'         => 'float',
                'value_float'        => 0.5,
                'default_value_text' => '0.5',
                'rationale_text'     => 'Множитель роста выкупа от суммарно собранной с жертвы подати (total_collected). 0.5 — чем больше выжал хозяин, тем дороже откупиться, но рост умеренный (hard_cap ограничивает сверху).',
                'effect_text'        => 'Слагаемое floor(total_collected * это) добавляется к base в формуле выкупа.',
                'above_effect_text'  => 'При 5.0 выкуп быстро упирается в hard_cap → формула вырождается в «всегда потолок».',
                'below_effect_text'  => 'При 0 выкуп всегда = base (не зависит от собранного).',
                'recommended_min'    => '0.2',
                'recommended_max'    => '1.0',
                'hard_min'           => '0',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'tribute.ransom_hard_cap',
                'value_type'         => 'int',
                'value_int'          => 15000,
                'default_value_text' => '15000',
                'rationale_text'     => 'Жёсткий потолок выкупа (анти-P2W/RMT). 15000 ≈ 15 дней налога постройки — достижимо разумным фармом любым игроком; «диких сумм» из исходной идеи «рабства» нет.',
                'effect_text'        => 'Итоговая стоимость выкупа не превышает это значение. 0 = без потолка (НЕ рекомендуется).',
                'above_effect_text'  => 'При 100000+ возвращается риск «диких сумм» → де-факто P2W-выдавливание бедных.',
                'below_effect_text'  => 'При 2000 (=base) выкуп всегда минимален → не зависит от собранного (теряется sink-масштабирование).',
                'recommended_min'    => '8000',
                'recommended_max'    => '30000',
                'hard_min'           => '0',
                'hard_max'           => '100000000',
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
            'tribute.ransom_base',
            'tribute.ransom_per_collected_k',
            'tribute.ransom_hard_cap',
        ])->delete();
    }
}
