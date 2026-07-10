<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Топ игроков (2026-07-10) — GameSettings по конституционному правилу ADMIN-TUNABLE BALANCE.
 *
 * Ship dormant (`enabled=false`): кнопка «🏆 Топ игроков» скрыта, callback отдаёт честное
 * «раздел отключён администрацией», текстовое слово «топ» — то же. Активация — через admin UI.
 *
 * Идемпотентность по `setting_key` (перезапуск миграции не клоббер ручную настройку).
 * `game_settings` = KEEP в WipeManifest → манифест не трогаем, новых таблиц нет.
 */
final class SeedLeaderboardGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'social.leaderboard.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch экрана «🏆 Топ игроков» (общий рейтинг по уровню). Построен потому, что аудит firehose (unrouted) показал: ДВА разных игрока спрашивали бота про топ и получали «Не понял команду», а общего рейтинга в игре не было (pvp.ladder — это рейтинг ДУЭЛЕЙ). Ship dormant: включаем осознанно, увидев экран на testbot. Рейтинг — чистый престиж, наград за место НЕТ (иначе он стал бы мотором гринда и наказал бы новичков).',
                'effect_text'        => 'LeaderboardService::enabled. true → кнопка «🏆 Топ игроков» в хабе «📊 Прогресс», callback leaderboard/leaderboardLegends рендерят экран, слова «топ»/«рейтинг» ведут туда же. false → кнопка скрыта, экран отвечает «отключён администрацией». Наружу видны только имя и уровень (ни золота, ни координат).',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = топ скрыт; на игру не влияет никак (экран read-only, наград нет).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'social.leaderboard.size',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Сколько строк показывать в топе. 10 — классический размер: помещается в одно сообщение вместе с личной позицией игрока и не превращает экран в простыню. На проде 611 персонажей, из них активных за неделю ~84 — десятки достаточно, чтобы верхушка была видна, а остальные ориентировались по строке «Ты: #N из M».',
                'effect_text'        => 'LeaderboardService::size — LIMIT в topActive()/topLegends(). Код дополнительно клампит в [3..25] на случай опечатки в админке.',
                'above_effect_text'  => 'Выше (20-25) → сообщение длиннее, у новичка верхушка списка уезжает вверх; при media-off это просто длинный текст. Свыше 25 код всё равно обрежет.',
                'below_effect_text'  => 'Ниже (3-5) → виден только пьедестал; средние игроки не видят достижимой цели, топ читается как «клуб избранных». Ниже 3 код не пустит.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '3',
                'hard_max'           => '25',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'social.leaderboard.active_days',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 7,
                'default_value_text' => '7',
                'rationale_text'     => 'Окно «живости» для вкладки «🔥 Живые» (по telegram_users.last_seen). 7 дней — потому что данные требуют: топ «за всё время» наполовину состоит из ветеранов L70-215, которые заблокировали бота. Для новичка (а на L1 сидит 86.6% аудитории) это стена из призраков, а не ориентир. Неделя отделяет тех, кто реально на острове, от легенд — последние живут на отдельной вкладке.',
                'effect_text'        => 'LeaderboardService::activeDays — граница last_seen в topActive()/rankOfActive()/totalActive(). Вкладка «👑 Легенды» окном не ограничена.',
                'above_effect_text'  => 'Выше (30-90) → в «Живых» всплывают ушедшие; вкладка теряет смысл и сливается с «Легендами».',
                'below_effect_text'  => 'Ниже (1-2) → в списке остаются лишь заходившие вчера; топ скачет день ото дня, а игрок, пропустивший выходные, выпадает («Ты пока не в списке живых»).',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '90',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        $db = $this->db;
        foreach ($rows as $row) {
            $exists = $db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->countAllResults();

            if ($exists === 0) {
                $db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'social.leaderboard.enabled',
                'social.leaderboard.size',
                'social.leaderboard.active_days',
            ])
            ->delete();
    }
}
