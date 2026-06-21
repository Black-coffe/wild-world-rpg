<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-136 (ROADMAP-100 продолжение воронки) — настройки comeback-пинга ушедшим новичкам
 * ({@see \App\TaskHandlers\Onboarding\ComebackNudgeHandler}, returnability Слой 3).
 *
 * 6 ключей категории world (онбординг/возвращаемость, как E4/E6). killswitch default OFF → DORMANT:
 * хендлер засижен, молчит до активации после Tier-3 (решение владельца — исходящая рассылка).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class Adr136ComebackNudgeGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'returnability.comeback.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch comeback-пинга (ADR-136, ComebackNudgeHandler): ушедшему новичку (level ≤ max, отсутствует в окне [min..max] часов, ещё достижим) шлётся ОДИН проактивный пинг с CTA на незавершённый онбординг-шаг. Атакует самую глубокую утечку воронки — ранний отток (срез A+B 06-20: D1≈45%/D7≈12.5%), который just-in-time хинты и E4 структурно не достают (требуют, чтобы игрок открыл экран/был онлайн). default=false → dormant: исходящая рассылка, активация после Tier-3.',
                'effect_text'        => 'ON: раз в сутки (час send_hour) хендлер выбирает ушедших новичков в окне отсутствия (one-shot НАВСЕГДА per char `ComebackPing`, opt-out daily_tips, blocked_at NULL) и шлёт comeback-текст; на 403 markBlocked (самоочистка). БЕЗ награды (анти-абьюз).',
                'above_effect_text'  => 'true: ушедшие новички получают напоминание-пинг → выше шанс вернуться (D1/D7). Риск — навязчивость/спам заблокировавшим, поэтому one-shot per char + окно + opt-out + markBlocked. Включать после Tier-3 текста/таймингов.',
                'below_effect_text'  => 'false: comeback-пинг не шлётся (dormant). Ушедший новичок не получает напоминания — только реактивный E6-digest при САМОСТОЯТЕЛЬНОМ возврате. Emergency-off при жалобах/баге.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'returnability.comeback.min_absent_hours',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 48,
                'default_value_text' => '48',
                'rationale_text'     => 'Минимальная давность отсутствия (часы), чтобы новичок считался «ушедшим» и получил comeback-пинг. 48ч (2 дня) = уверенно после D1, игрок явно застопорился, но ещё в зоне восстановления (до D7). Меньше — рискуем пингать тех, кто просто отошёл на день (это работа E4 для онлайна).',
                'effect_text'        => 'Нижняя граница окна в fetchAbsent: last_seen <= NOW() - min_absent_hours. Чем меньше — тем раньше после ухода приходит пинг.',
                'above_effect_text'  => 'Выше (напр. 96): пинг только сильно остывшим (4+ дня) — меньше ложных срабатываний на «отошёл», но часть восстановимых упустим (ближе к D7-краю).',
                'below_effect_text'  => 'Ниже (напр. 12): пинг почти сразу после ухода — рискует надоесть тому, кто вернулся бы сам; пересечение с зоной E4 (онлайн).',
                'recommended_min'    => '24',
                'recommended_max'    => '96',
                'hard_min'           => '1',
                'hard_max'           => '720',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'returnability.comeback.max_absent_hours',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 168,
                'default_value_text' => '168',
                'rationale_text'     => 'Максимальная давность отсутствия (часы) для comeback-пинга. 168ч (7 дней) = граница D7: дальше игрок считается сгинувшим, пуш мёртвому аккаунту = спам (и многие уже заблокировали бота). Окно [min..max] = «ушёл, но ещё восстановим».',
                'effect_text'        => 'Верхняя граница окна в fetchAbsent: last_seen >= NOW() - max_absent_hours. Чем больше — тем более «остывших» ещё пингаем.',
                'above_effect_text'  => 'Выше (напр. 336): пингаем ушедших до 2 недель — шире охват, но ниже отклик и выше доля заблокировавших (пуш «в спину» давно ушедшему).',
                'below_effect_text'  => 'Ниже (напр. 96): только недавно ушедшие (≤4 дня) — выше отклик на пинг, но меньше охват (узкое окно).',
                'recommended_min'    => '72',
                'recommended_max'    => '336',
                'hard_min'           => '2',
                'hard_max'           => '2160',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'returnability.comeback.max_level',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Потолок уровня «новичка» для comeback-пинга. 6 = тот же порог раннего онбординга, что у контекстных хинтов/E4. Ушедший ветеран (выше порога) — осознанный перерыв, проактивный пинг ему = спам; comeback бьёт строго по раннему оттоку, где напоминание реально помогает.',
                'effect_text'        => 'Гейт c.level <= max_level в fetchAbsent. Выше потолка пинг не шлётся.',
                'above_effect_text'  => 'Выше (напр. 15): пингаем и ушедших мид-новичков L7-15 — шире, но пинг менее уместен (они уже освоились, ушли осознанно).',
                'below_effect_text'  => 'Ниже (напр. 3): только самых ранних (L1-3) — точнее по «не понял старт», но часть ушедших L4-6 пропустим.',
                'recommended_min'    => '3',
                'recommended_max'    => '15',
                'hard_min'           => '1',
                'hard_max'           => '100',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'returnability.comeback.send_hour',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 11,
                'default_value_text' => '11',
                'rationale_text'     => 'Час серверного времени для дневной рассылки comeback-пингов. 11 = разумное дневное время (не 3 ночи), отличается от часа «Совета дня» (10) → нет коллизии двойной отправки в одну минуту. Once/day-claim гарантирует один прогон в сутки.',
                'effect_text'        => 'hour-guard: handle() шлёт только если текущий серверный час = send_hour. Прочие тики everyMinute выходят сразу.',
                'above_effect_text'  => 'Позже (напр. 19): пинги вечером. Разумно, если активность аудитории смещена к вечеру.',
                'below_effect_text'  => 'Раньше (напр. 8): пинги утром. Слишком рано/ночью — риск разбудить уведомлением, ощущение спама.',
                'recommended_min'    => '8',
                'recommended_max'    => '21',
                'hard_min'           => '0',
                'hard_max'           => '23',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'returnability.comeback.max_per_run',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Максимум comeback-пингов за дневной прогон — защита от лавины и Telegram rate-limit при накоплении ушедших. Остаток сверх кэпа подхватится в следующие сутки (one-shot per char не даст задвоить). 50/день — мягкий ramp для текущей аудитории (~93 рег/14д).',
                'effect_text'        => 'LIMIT выборки fetchAbsent за прогон. При throttle ~28 msg/sec реальное время прогона невелико.',
                'above_effect_text'  => 'Выше (напр. 200): за прогон охватываем больше ушедших, но дольше держим прогон и ближе к rate-limit при всплеске регистраций.',
                'below_effect_text'  => 'Ниже (напр. 10): прогон короче, но при наплыве ушедших очередь пингов растягивается на несколько дней.',
                'recommended_min'    => '10',
                'recommended_max'    => '200',
                'hard_min'           => '1',
                'hard_max'           => '500',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->countAllResults();
            if ($exists === 0) {
                $this->db->table('game_settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'returnability.comeback.enabled',
            'returnability.comeback.min_absent_hours',
            'returnability.comeback.max_absent_hours',
            'returnability.comeback.max_level',
            'returnability.comeback.send_hour',
            'returnability.comeback.max_per_run',
        ])->delete();
    }
}
