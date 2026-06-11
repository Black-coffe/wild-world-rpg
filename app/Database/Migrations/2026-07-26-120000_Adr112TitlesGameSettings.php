<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-112 (E11) — настройки системы титулов.
 *
 * 2 ключа категории endgame. killswitch default OFF → DORMANT (титулы не выдаются и не
 * показываются до активации после Tier-3). Seed game_settings (KEEP). Idempotent (setting_key).
 * ADR-024: rationale/effect/above/below NOT NULL.
 */
class Adr112TitlesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'titles.enabled',
                'category'           => 'endgame',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch системы титулов (E11, ADR-112): видимый статус-тег за уровень/достижения, один активный титул, выбор в профиле. default=false → dormant: TitleCheckCron не выдаёт, строка титула и кнопка скрыты до активации после Tier-3.',
                'effect_text'        => 'ON: TitleCheckCron (everyMinute) выдаёт титулы по источнику (level≥N / наличие достижения), батч-уведомление «🎖 Новый титул», первый авто-экипируется; карточка Перса показывает активный титул + кнопку «🎖 Титулы». OFF: ничего не выдаётся/не показывается.',
                'above_effect_text'  => 'true: у игроков появляется видимая идентичность (ранняя соц-мотивация, цель E11). Включать после Tier-3.',
                'below_effect_text'  => 'false: титулов нет (dormant). Emergency-off при проблемах.',
                'recommended_min'    => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'setting_key'        => 'titles.max_awards_per_tick',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Сколько выдач титулов за один тик cron — throttle против notification-шторма при активации (сотни существующих игроков разом получают level-титулы). 25 — как у достижений (ADR-066).',
                'effect_text'        => 'Верхний предел award за тик TitleCheckCron; остаток догонится в следующие тики.',
                'above_effect_text'  => 'Выше: быстрее раздача при активации, но риск всплеска уведомлений.',
                'below_effect_text'  => 'Ниже: плавнее раздача, дольше начальный прогон.',
                'recommended_min'    => '5', 'recommended_max' => '100', 'hard_min' => '1', 'hard_max' => '1000',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'titles.enabled', 'titles.max_awards_per_tick',
        ])->delete();
    }
}
