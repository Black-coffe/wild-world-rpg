<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W18 (vNext2, Фаза 4, ADR-072) — GameSettings PvP-ладдера (admin-tunable, ADR-024).
 *
 * `pvp.ladder.enabled` (bool, default **false = DORMANT**): scoring (recordDuel/recordPvpAttack),
 * кнопка «🏆 Рейтинг PvP» и weekly-broadcast не активны → pvp_ladder пуст, 0 эффекта.
 * Очки tunable. broadcast_enabled — отдельный killswitch (ладдер можно включить без weekly-спама). Idempotent.
 */
class W18SeedPvpLadderGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'pvp.ladder.enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch PvP-ладдера (ADR-072). true — дуэли (W17) и PvP-атаки пишут результат в pvp_ladder, видна кнопка «🏆 Рейтинг PvP», доступен экран рейтинга. false — DORMANT: scoring no-op, кнопка/экран скрыты, таблица пуста (0 эффекта). Активация в конце ROADMAP.',
                'effect_text'        => 'PvpLadderService::enabled. true → recordDuel/recordPvpAttack инкрементят очки; PvpLadderAction рендерит топ; CharacterService показывает кнопку.',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = ладдер выключен (бой/дуэли не затронуты, просто не пишут очки).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.points_duel_win',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Очки за победу в дуэли (W17). 3 — выше ничьей, ниже летальной PvP-победы: дуэль безопасна (без потерь), поэтому дешевле риск-PvP. Баланс престижа.',
                'effect_text'        => 'PvpLadderService::recordDuel начисляет победителю.',
                'above_effect_text'  => 'Выше → дуэли доминируют в рейтинге над летальным PvP (топ = самые активные дуэлянты).',
                'below_effect_text'  => 'Ниже → дуэли почти не двигают рейтинг (демотивирует opt-in PvP).',
                'recommended_min'    => '1',
                'recommended_max'    => '10',
                'hard_min'           => '0',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.points_duel_draw',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'Очки за ничью в дуэли (обоим). 1 — поощряет участие даже без победы (равный бой выдохшихся), но втрое меньше победы.',
                'effect_text'        => 'PvpLadderService::recordDuel при isDraw начисляет обоим.',
                'above_effect_text'  => 'Выше → фарм ничьих становится выгоден (спам безопасных дуэлей до exhausted).',
                'below_effect_text'  => 'Ниже/0 → ничья ничего не даёт (только чистые победы в счёт).',
                'recommended_min'    => '0',
                'recommended_max'    => '3',
                'hard_min'           => '0',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.points_pvp_win',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Очки за победу в реальной (летальной) PvP-атаке. 5 — дороже дуэли: настоящее PvP несёт риск death/XP-loss, выше ставка → выше награда престижа.',
                'effect_text'        => 'PvpLadderService::recordPvpAttack начисляет победителю.',
                'above_effect_text'  => 'Выше → летальное PvP резко доминирует в топе (поощряет агрессию против слабых).',
                'below_effect_text'  => 'Ниже → летальное PvP не отличается от дуэли по престижу (теряет смысл рисковать).',
                'recommended_min'    => '1',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.broadcast_enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch еженедельной рассылки топа ладдера всем игрокам. Отдельно от ladder.enabled → можно включить рейтинг (scoring+UI) БЕЗ weekly-спама 364 игрокам, пока не накопятся данные. false — broadcast не шлётся, week_* не сбрасывается.',
                'effect_text'        => 'PvpLadderWeeklyBroadcastHandler: при true (+ ladder.enabled) в день/час рассылает топ-N и сбрасывает week_*.',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (default) = нет рассылки, нет недельного сброса (рейтинг копится cumulative).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.broadcast_top_n',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Сколько топ-позиций показывать в weekly-broadcast и на экране рейтинга. 5 — компактно для Telegram-сообщения, достаточно для престижа.',
                'effect_text'        => 'PvpLadderService::topGlobal/topByFaction limit + broadcast.',
                'above_effect_text'  => 'Выше → длиннее сообщение (риск caption-лимита при больших списках).',
                'below_effect_text'  => 'Ниже → только самая верхушка (меньше игроков чувствуют признание).',
                'recommended_min'    => '3',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '50',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.broadcast_day',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'День недели для weekly-broadcast (1=Пн … 7=Вс, по ISO date(N)). 1=Понедельник — старт новой недели/сезона.',
                'effect_text'        => 'PvpLadderWeeklyBroadcastHandler day-guard (date(N)).',
                'above_effect_text'  => 'Другой день недели сдвигает рассылку/сброс сезона.',
                'below_effect_text'  => 'То же.',
                'recommended_min'    => '1',
                'recommended_max'    => '7',
                'hard_min'           => '1',
                'hard_max'           => '7',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'pvp.ladder.broadcast_hour',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'Час (серверное время) для weekly-broadcast. 12 — полдень, дневная активность.',
                'effect_text'        => 'PvpLadderWeeklyBroadcastHandler hour-guard (date(G)).',
                'above_effect_text'  => 'Позже днём/ночью — меньше онлайна на момент рассылки.',
                'below_effect_text'  => 'Раньше утром — то же.',
                'recommended_min'    => '8',
                'recommended_max'    => '21',
                'hard_min'           => '0',
                'hard_max'           => '23',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'pvp.ladder.enabled',
                'pvp.ladder.points_duel_win',
                'pvp.ladder.points_duel_draw',
                'pvp.ladder.points_pvp_win',
                'pvp.ladder.broadcast_enabled',
                'pvp.ladder.broadcast_top_n',
                'pvp.ladder.broadcast_day',
                'pvp.ladder.broadcast_hour',
            ])->delete();
    }
}
