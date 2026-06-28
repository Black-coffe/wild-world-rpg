<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S7 (ROADMAP-RETENTION-10, ADR-145) — гейт «живого острова» (IslandPulseService).
 *
 * Экран «🌍 Остров живёт» показывает ПРАВДИВЫЙ пульс активности реальных выживших
 * (исследованные клетки / высадки / последняя база / стычки) — только true-data, без
 * фабрикации «N онлайн».
 *
 *   - enabled (killswitch, default OFF → dormant): кнопка «🌍 Остров живёт» и тизер на карте
 *     не добавляются, MoveCharacterAction byte-identical.
 *   - window_hours / survivors_days — окна агрегации (что считать «свежим»).
 *
 * Без новой таблицы (live-запросы к existing tables). game_settings = KEEP (WipeManifest не
 * трогаем). Idempotent. Категория world.
 */
class S7IslandPulseGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'social.presence.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S7 «живого острова». false (default) — DORMANT: кнопка «🌍 Остров живёт» и тизер в подвале карты не добавляются, MoveCharacterAction byte-identical. true — игрок видит экран с ПРАВДИВЫМ пульсом активности реальных выживших (исследовано N клеток за сутки, последняя база поднята K назад, за неделю высадилось L, всего T). 🔴 Только true-data: НИКОГДА не фабрикуем «N онлайн»/decoy. Бьёт в «мир обитаем» рано (до фракций L10), синергия с S2-встречей. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'IslandPulseService::enabled гейтит кнопку+тизер в MoveCharacterAction и сам экран IslandAction.',
                'above_effect_text'  => 'true — игрок видит правдивые следы других выживших → ощущение населённого (пусть и редкого) мира, причина «ещё разок»; поддержка D2-D7.',
                'below_effect_text'  => 'false — мир ощущается одиночным (карта показывает лишь свою позицию/базы), социальных сигналов рано нет; текущее поведение.',
            ],
            [
                'setting_key'        => 'social.presence.window_hours',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'recommended_min'    => 6,
                'recommended_max'    => 72,
                'hard_min'           => 1,
                'hard_max'           => 168,
                'rationale_text'     => 'Окно «свежей» активности (часы) для строк исследования и стычек. 24 = «за сутки» — естественная единица, даёт ненулевые числа при текущем трафике (~850 клеток/сутки) и читается как «сегодня остров жил».',
                'effect_text'        => 'IslandPulseService::windowHours — окно COUNT по explored_cells/battle_logs.created_at.',
                'above_effect_text'  => 'Выше — числа крупнее (накапливается больше), но «свежесть» теряется (показываем старое как «сейчас»).',
                'below_effect_text'  => 'Ниже — числа честнее-свежее, но при низком трафике чаще будут 0 → экран чаще показывает честную «тихо».',
            ],
            [
                'setting_key'        => 'social.presence.survivors_days',
                'value_type'         => 'int',
                'value_int'          => 7,
                'default_value_text' => '7',
                'recommended_min'    => 3,
                'recommended_max'    => 30,
                'hard_min'           => 1,
                'hard_max'           => 90,
                'rationale_text'     => 'Окно «высадок» (дни) для строки «за неделю высадилось N». 7 = «за неделю» — при текущем интейке (~13/нед) даёт осмысленное ненулевое число, показывает приток новых выживших.',
                'effect_text'        => 'IslandPulseService::survivorsDays — окно COUNT по characters.created_at.',
                'above_effect_text'  => 'Выше — больше накопленных высадок в строке (но менее «недавних»).',
                'below_effect_text'  => 'Ниже — только самые свежие высадки; при малом интейке чаще 0 (строка скрывается).',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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
            'social.presence.enabled',
            'social.presence.window_hours',
            'social.presence.survivors_days',
        ])->delete();
    }
}
