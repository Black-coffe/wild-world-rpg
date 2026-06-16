<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-132 — GameSettings лестницы вех серии (категория world).
 *
 *   • returnability.streak.milestones_enabled (bool, default false → DORMANT) — killswitch;
 *   • returnability.streak.milestones_max_awards_per_tick (int, 25) — throttle выдач за тик крона
 *     (анти-notification-шторм при активации, когда сотни существующих чаров разом пробивают вехи).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class Adr132StreakMilestonesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'returnability.streak.milestones_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch лестницы вех серии (ADR-132, надстройка над дневным стриком ADR-108 Ф3): на вехах 3/5/7/10/15/30/50/100 дней подряд игрок получает награду с растущим престижем (низкие — припасы, высокие — уникальные титулы). default=false → dormant. Цель — ретеншн: дать длинной серии видимую цель сверх дневного золота.',
                'effect_text'        => 'StreakMilestoneCron (everyMinute) при ON находит чаров, чей login_streak ≥ порога вехи и веха ещё не забрана → выдаёт награду (золото/ресурс/титул) идемпотентно + батч-уведомление. Титулы видны в карточке Перса и публичном веб-профиле.',
                'above_effect_text'  => 'true: вехи начисляются, длинная серия даёт растущий престиж → сильнее тяга заходить ежедневно. Инъекция золота только на низких вехах (3/5/7, capped); 10+ — преимущественно/только престиж (анти-инфляция).',
                'below_effect_text'  => 'false: вехи не выдаются (dormant). Дневной стрик ADR-108 продолжает работать независимо. Emergency-off.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'returnability.streak.milestones_max_awards_per_tick',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Сколько выдач вех за один тик крона (throttle анти-шторм). 25 = при активации на проде (сотни чаров с уже накопленной серией разом пробивают вехи) рассылка размазывается по тикам, не упирается в Telegram rate-limit. Зеркало achievement.max_awards_per_tick / titles.max_awards_per_tick.',
                'effect_text'        => 'StreakMilestoneCron прекращает выдачу, достигнув этого числа за тик; остаток догоняется на следующих минутах (idempotent — claimed-таблица).',
                'above_effect_text'  => 'Выше: быстрее разгребается backlog вех при активации, но выше риск всплеска уведомлений/нагрузки за один тик.',
                'below_effect_text'  => 'Ниже: мягче нагрузка за тик, но backlog вех при массовой активации разгребается дольше.',
                'recommended_min'    => 5,
                'recommended_max'    => 200,
                'hard_min'           => 1,
                'hard_max'           => 10000,
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
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'returnability.streak.milestones_enabled',
            'returnability.streak.milestones_max_awards_per_tick',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
