<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 — GameSettings для «Трофейной подати» (admin-tunable, ADR-024).
 *
 * Все ключи под killswitch tribute.enabled=false (dormant до Фазы 5). category='combat'
 * (механика триггерится PvP-доминированием). Каждый ключ с rich rationale
 * (why/effect/above/below) — invariant ADR-024. Idempotent (по setting_key).
 *
 * Фаза 0 сидит ядро (триггер/сбор/защита). Выкуп (ransom_*) и bounty (bounty_*) —
 * отдельной seed-миграцией в Фазе 3, когда код их читает (анти-hardcode без раннего долга).
 */
class Adr135TributeGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            // ── Killswitch ──────────────────────────────────────────────
            [
                'setting_key'        => 'tribute.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Главный killswitch «Трофейной подати» (ADR-135). false = dormant: доминирование не трекается, подать не создаётся и не собирается, кнопки/уведомления скрыты. Включать только после Tier-1/2/3 + анонса (Фаза 5).',
                'effect_text'        => 'true → весь конвейер подати активен (трекинг доминирования → создание → сбор 10% с добычи → снятие). false → полностью выключено без редеплоя.',
                'above_effect_text'  => 'true (вкл): живая PvP-механика принудительного налога — только после полного smoke и анонса игрокам.',
                'below_effect_text'  => 'false (выкл): мгновенный откат всей механики (например при эксплойте/сговоре).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],

            // ── Экономика подати (zero-sum переток) ─────────────────────
            [
                'setting_key'        => 'tribute.rate',
                'value_type'         => 'float',
                'value_float'        => 0.10,
                'default_value_text' => '0.10',
                'rationale_text'     => 'Доля raw-добычи жертвы, перетекающая хозяину (zero-sum: жертва −rate, хозяин +rate, ровно). 0.10 — мягкий потолок: ощутимо, но не «доилка». hard_max=0.10 — выше нельзя без нового ADR (экономист: защита от harassment-эскалации).',
                'effect_text'        => 'На каждом начислении добычи (GatherResultPersister::persist): tribute = floor(gathered × rate); жертва net = gathered − tribute; хозяин += tribute. Инвариант Σжертва_net + Σхозяин_in == Σgathered. Только raw-добыча, НЕ крафт/золото.',
                'above_effect_text'  => 'Выше 0.10 (заблокировано hard_max): подать становится инструментом выдавливания слабых → пробой контракта П2/П9.',
                'below_effect_text'  => 'При 0.02 переток символический → механика «мёртвая», хищнику неинтересно (BUILT-BUT-DEAD-риск).',
                'recommended_min'    => '0.05',
                'recommended_max'    => '0.10',
                'hard_min'           => '0',
                'hard_max'           => '0.10',
            ],
            [
                'setting_key'        => 'tribute.daily_cap_per_master',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'rationale_text'     => 'Дневной потолок единиц ресурса, перетекающих ОДНОМУ хозяину суммарно со всех данников. Анти-снежок: магнат с несколькими данниками не ломает баланс экспоненциальным притоком.',
                'effect_text'        => 'При сборе: если collected_today хозяина достиг этого значения — переток на сегодня прекращается (жертва оставляет 100%). Сброс при смене суток.',
                'above_effect_text'  => 'При 5000+ cap фактически снят → доминатор копит ресурс лавиной (rich-get-richer death-spiral).',
                'below_effect_text'  => 'При 10 подать почти ничего не даёт → механика вялая, статус без выгоды.',
                'recommended_min'    => '100',
                'recommended_max'    => '1000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],

            // ── Триггер доминирования ───────────────────────────────────
            [
                'setting_key'        => 'tribute.dominance_wins_required',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Сколько побед над одной жертвой за окно нужно, чтобы наложить подать. 5 — высокий порог (исходная идея игроков): подать = за устойчивое превосходство, не за один удачный фраг.',
                'effect_text'        => 'TributeService считает победы master над vassal в battle_logs за dominance_window_days; при достижении (И 0 поражений в окне) создаётся подать.',
                'above_effect_text'  => 'При 15+ закабалить почти нереально → механика «мёртвая».',
                'below_effect_text'  => 'При 1 одна победа = подать → сталкинг/респавн-фарм, провал П4 (награда за упорство, не скилл).',
                'recommended_min'    => '3',
                'recommended_max'    => '10',
                'hard_min'           => '1',
                'hard_max'           => '100',
            ],
            [
                'setting_key'        => 'tribute.dominance_window_days',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Окно (дней назад от текущего боя), в котором считаются победы над жертвой. 30 — «за месяц» из исходной идеи. Победы старше окна не учитываются (нельзя копить «долг» годами).',
                'effect_text'        => 'TributeService учитывает только battle_logs за последние N дней при подсчёте доминирования.',
                'above_effect_text'  => 'При 365 победы копятся почти бессрочно → жертва под вечной угрозой.',
                'below_effect_text'  => 'При 1 нужно 5 побед за сутки → провоцирует серийный респавн-фарм за вечер.',
                'recommended_min'    => '14',
                'recommended_max'    => '60',
                'hard_min'           => '1',
                'hard_max'           => '3650',
            ],

            // ── Срочность и анти-сталкинг ───────────────────────────────
            [
                'setting_key'        => 'tribute.duration_days',
                'value_type'         => 'int',
                'value_int'          => 14,
                'default_value_text' => '14',
                'rationale_text'     => 'Срок подати до авто-снятия (expires_at = started_at + N дней). НЕ «навсегда» — временность снимает оффлайн/harassment-риск (П2/П10). Реванш снимает раньше.',
                'effect_text'        => 'Идемпотентный task-handler (Фаза 3) переводит подать в status=expired по достижении expires_at. До этого — активна (если не снята реваншем/выкупом).',
                'above_effect_text'  => 'При 90+ подать почти бессрочна → возвращается harassment-механика «вечного ошейника».',
                'below_effect_text'  => 'При 1 подать снимается за сутки → бессмысленна, статус без последствий.',
                'recommended_min'    => '7',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '365',
            ],
            [
                'setting_key'        => 'tribute.recapture_cooldown_days',
                'value_type'         => 'int',
                'value_int'          => 7,
                'default_value_text' => '7',
                'rationale_text'     => 'После снятия подати нельзя пере-обложить ту же жертву раньше N дней. Анти-сталкинг: не дать держать одного человека в бесконечной цепочке податей.',
                'effect_text'        => 'TributeService при попытке создать подать проверяет, что последняя снятая подать на эту пару (master→vassal) старше N дней; иначе отказ.',
                'above_effect_text'  => 'При 60+ хозяин фактически теряет жертву надолго → подать «одноразовая».',
                'below_effect_text'  => 'При 0 хозяин накладывает подать сразу после снятия → бесконечный ошейник (провал П10).',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '0',
                'hard_max'           => '365',
            ],

            // ── Защита слабых / новичков ────────────────────────────────
            [
                'setting_key'        => 'tribute.min_level',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'Минимальный уровень ЖЕРТВЫ, чтобы попасть под подать. 20 — эндгейм-гейт: механика грызёт высокоуровневых PvP-игроков, новичок/казуал физически вне неё (защита воронки, П10/П2).',
                'effect_text'        => 'TributeService не создаёт подать, если уровень жертвы ниже значения. Раннюю игру (ур.<20) подать не касается вообще.',
                'above_effect_text'  => 'При 40+ под механику попадает лишь верхушка → почти не работает при тонком онлайне.',
                'below_effect_text'  => 'При 5 под подать попадают полу-новички → провал защиты ранней игры (П10), ускорение оттока.',
                'recommended_min'    => '15',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '999',
            ],
            [
                'setting_key'        => 'tribute.max_level_gap',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Максимальная разница уровней (master − vassal), при которой можно наложить подать. Анти-избиение: нельзя обложить того, кто слабее более чем на N уровней (защита от «топ доит середняка»).',
                'effect_text'        => 'TributeService не создаёт подать, если master выше vassal более чем на N уровней.',
                'above_effect_text'  => 'При 60 гейт фактически снят → сильный обкладывает кого угодно слабее.',
                'below_effect_text'  => 'При 1 подать почти невозможна (нужны равные по уровню) → механика редкая.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '999',
            ],
            [
                'setting_key'        => 'tribute.min_account_age_days',
                'value_type'         => 'int',
                'value_int'          => 21,
                'default_value_text' => '21',
                'rationale_text'     => 'Минимальный возраст аккаунта ЖЕРТВЫ (дней с регистрации), чтобы попасть под подать. 21 — выше PvP-гейта (10 дней): новый игрок защищён дополнительно (П10).',
                'effect_text'        => 'TributeService не создаёт подать, если аккаунт жертвы младше N дней.',
                'above_effect_text'  => 'При 90+ под механику попадают лишь старожилы → редко срабатывает.',
                'below_effect_text'  => 'При 0 новорегистрант сразу под угрозой подати → отток на входе.',
                'recommended_min'    => '14',
                'recommended_max'    => '45',
                'hard_min'           => '0',
                'hard_max'           => '3650',
            ],
            [
                'setting_key'        => 'tribute.respawn_immunity_hours',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'rationale_text'     => 'Окно иммунитета (часов после респавна), в течение которого победы над жертвой НЕ засчитываются в доминирование. Закрывает серийный respawn-farming (которого PvP-защита сейчас не покрывает).',
                'effect_text'        => 'TributeService игнорирует победы над жертвой, если с её last_respawn_at прошло менее N часов.',
                'above_effect_text'  => 'При 168 (неделя) жертву почти невозможно «дожать» → доминирование редко набирается.',
                'below_effect_text'  => 'При 0 хозяин добивает жертву серией сразу после респавна → провоцирует фарм одной цели за вечер.',
                'recommended_min'    => '6',
                'recommended_max'    => '48',
                'hard_min'           => '0',
                'hard_max'           => '720',
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
            'tribute.enabled',
            'tribute.rate',
            'tribute.daily_cap_per_master',
            'tribute.dominance_wins_required',
            'tribute.dominance_window_days',
            'tribute.duration_days',
            'tribute.recapture_cooldown_days',
            'tribute.min_level',
            'tribute.max_level_gap',
            'tribute.min_account_age_days',
            'tribute.respawn_immunity_hours',
        ])->delete();
    }
}
