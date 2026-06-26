<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S6 (ROADMAP-RETENTION-10, ADR-140) — day-2 пинг: проактивный возврат в окне D1→D2.
 *
 * Самая глубокая утечка — обвал D1≈45%→D7≈12.5% начинается с невозврата на ДЕНЬ ВТОРОЙ.
 * Comeback-пинг (ADR-136) бьёт только с 48ч отсутствия — окно D1→D2 (16-44ч) НЕ покрыто
 * проактивным исходящим. Инфра «поводов вернуться» (серия входов / задания дня / digest)
 * УЖЕ live, но новичок о ней не знает, пока не зайдёт. Day2NudgeHandler шлёт ОДИН тёплый пинг
 * в окне дня-2, называя КОНКРЕТНЫЕ live-поводы (серия / дейлики). Ниже 48ч-пола comeback'а
 * (не пересекаются: разные one-shot маркеры). enabled=false (default) → DORMANT, крон no-op.
 * game_settings = KEEP (WipeManifest не трогаем). Idempotent. Категория world.
 */
class Adr140Day2NudgeGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'returnability.day2.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S6 day-2 пинга (Day2NudgeHandler). false (default) — DORMANT: крон no-op, ничего не шлётся. true — новичку (level ≤ max_level), ушедшему в окно [min..max] часов и ещё достижимому, шлётся ОДИН проактивный пинг с конкретными live-поводами вернуться (серия входов / задания дня). Заполняет окно D1→D2, которое comeback-пинг (ADR-136, с 48ч) не покрывает. ОТДЕЛЬНЫЙ от comeback (свой one-shot маркер Day2Ping). Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'Day2NudgeHandler::handle гейтит рассылку по этому флагу (everyMinute + hour-guard + once-day-claim).',
                'above_effect_text'  => 'true — ушедшие в день-2 новички получают напоминание о конкретных наградах за возврат → удар по обвалу D1→D7 (главный рычаг ретеншна).',
                'below_effect_text'  => 'false — day-2 окно остаётся без проактивного пинга (только пассивные подсказки + comeback с 48ч); текущее поведение.',
            ],
            [
                'setting_key'        => 'returnability.day2.min_absent_hours',
                'value_type'         => 'int',
                'value_int'          => 16,
                'default_value_text' => '16',
                'recommended_min'    => 8,
                'recommended_max'    => 24,
                'hard_min'           => 1,
                'hard_max'           => 47,
                'rationale_text'     => 'Нижняя граница окна отсутствия для day-2 пинга. 16 — игрок ушёл с прошлой сессии достаточно, чтобы напоминание было уместно (не дёргаем активного), но ещё в пределах «дня второго». Ниже 48 (пол comeback) — окна не пересекаются.',
                'effect_text'        => 'Day2NudgeHandler::fetchAbsent — last_seen старше этого числа часов.',
                'above_effect_text'  => 'Выше — пинг позже, ближе к comeback-окну (рискует слиться с ним по смыслу).',
                'below_effect_text'  => 'Ниже (напр. 6) — пинг слишком рано, можем дёрнуть того, кто просто отвлёкся на пару часов.',
            ],
            [
                'setting_key'        => 'returnability.day2.max_absent_hours',
                'value_type'         => 'int',
                'value_int'          => 44,
                'default_value_text' => '44',
                'recommended_min'    => 30,
                'recommended_max'    => 47,
                'hard_min'           => 2,
                'hard_max'           => 47,
                'rationale_text'     => 'Верхняя граница окна day-2 пинга. 44 — конец «дня второго», ДО 48ч-пола comeback (ADR-136) → окна не пересекаются, игрок не получит два пинга в один час. Кто ушёл дольше — подхватит comeback.',
                'effect_text'        => 'Day2NudgeHandler::fetchAbsent — last_seen не старше этого числа часов.',
                'above_effect_text'  => 'Выше 47 — пересечение с comeback-окном (48ч+): возможен двойной пинг → спам.',
                'below_effect_text'  => 'Ниже — короче окно охвата дня-2 (меньше новичков попадёт в пинг за тик).',
            ],
            [
                'setting_key'        => 'returnability.day2.max_level',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'recommended_min'    => 3,
                'recommended_max'    => 10,
                'hard_min'           => 1,
                'hard_max'           => 100,
                'rationale_text'     => 'Потолок уровня «новичка» для day-2 пинга. 6 — единый с comeback/онбордингом порог (contextual_hints.max_level). Ветеранов (выше) не трогаем — у них своя мотивация, пуш = спам.',
                'effect_text'        => 'Day2NudgeHandler::fetchAbsent — characters.level ≤ этого значения.',
                'above_effect_text'  => 'Выше — пинг дойдёт до игроков постарше (риск спама тем, кто и так в игре).',
                'below_effect_text'  => 'Ниже — уже когорта (только самые свежие новички).',
            ],
            [
                'setting_key'        => 'returnability.day2.send_hour',
                'value_type'         => 'int',
                'value_int'          => 18,
                'default_value_text' => '18',
                'recommended_min'    => 10,
                'recommended_max'    => 21,
                'hard_min'           => 0,
                'hard_max'           => 23,
                'rationale_text'     => 'Серверный час суток для рассылки day-2 пингов (одна волна в день, once/day-claim). 18 — вечер, высокий шанс, что игрок у телефона; не ночь. Совпадает с comeback.send_hour, но окна не пересекаются → один игрок не получит оба.',
                'effect_text'        => 'Day2NudgeHandler::handle — hour-guard: рассылка только в этот час.',
                'above_effect_text'  => 'Иной час → иное время доставки (это выбор времени, не «больше/меньше»).',
                'below_effect_text'  => 'Иной час → иное время; ночные часы (тихие 23-8) гасятся политикой уведомлений всё равно.',
            ],
            [
                'setting_key'        => 'returnability.day2.max_per_run',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'recommended_min'    => 20,
                'recommended_max'    => 200,
                'hard_min'           => 1,
                'hard_max'           => 500,
                'rationale_text'     => 'Батч-кап пингов за один тик (throttle ~28/сек < лимит Telegram 30/сек). 50 — с запасом для текущей аудитории; остаток подхватится завтра (one-shot не задвоит).',
                'effect_text'        => 'Day2NudgeHandler::fetchAbsent — LIMIT на выборку за тик.',
                'above_effect_text'  => 'Выше — больше пингов за тик; при большой аудитории риск приблизиться к rate-limit Telegram.',
                'below_effect_text'  => 'Ниже — медленнее охват большой когорты (растягивается на несколько дней).',
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
            'returnability.day2.enabled',
            'returnability.day2.min_absent_hours',
            'returnability.day2.max_absent_hours',
            'returnability.day2.max_level',
            'returnability.day2.send_hour',
            'returnability.day2.max_per_run',
        ])->delete();
    }
}
