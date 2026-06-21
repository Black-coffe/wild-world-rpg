<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Onboarding\OnboardingChainCatalog;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * E1 (ROADMAP-100-SESSIONS) — агрегатор воронки новичка для admin-дашборда `/admin/funnel`.
 *
 * Read-only: только агрегирующие SELECT'ы. Активность меряется ДВИЖЕНИЕМ
 * (explored_cells.created_at) — это единственный полный таймлайн player-действий:
 * action_log покрывает лишь отдельные экономические действия, character_tasks чистится.
 * «Достижимость» — telegram_users.blocked_at IS NULL.
 *
 * Baseline-факты E1 (2026-06-09), против которых меряется блок E-A:
 * total 482 / reachable 145 / active_14d(move) 61 / 429 на L1 / клетка 1 = 166 чаров
 * (легаси-респавн, 142 blocked, 0 движения за 30 дн) / июнь: 116 рег., 54% без единого шага.
 */
final class FunnelAnalyticsService
{
    /** Активация расширенных квестов ADR-088 на проде (runbook quest-adr088-analytics-slice). */
    private const QUEST_ACTIVATION = '2026-06-02 09:33:00';

    /** objective_type расширенных квестов ADR-088 (NEW-бакет). */
    private const EXTENDED_TYPES = "('collect_resource','building_level','level_milestone','npc_kills')";

    /** Активация ADR-097 (faction-choice nudge: value-prop + 1000💰 + анти-спам) на проде. */
    private const FACTION_NUDGE_ACTIVATION = '2026-06-04 00:00:00';

    /** faction_id → имя (как в ChooseFaction); 5 = плейсхолдер «не выбрана». */
    private const FACTION_NAMES = [
        1 => '🛡️ Милитари', 2 => '🌲 Партизаны', 3 => '🛠️ Инженеры', 4 => '🌾 Фермеры',
    ];

    /**
     * E5 Ф4 (ADR-104) — активация E5 на проде: Ф1 стартовый набор 13:04
     * (Ф3b момент удачи 13:52, Ф2 быстрый цикл 15:20 — тот же день).
     * Когорта «после» меряется от первой активации.
     */
    private const E5_ACTIVATION = '2026-06-10 13:04:00';

    /** Глубина когорты «до» (дней до активации) для сравнения E5. */
    private const E5_BEFORE_DAYS = 14;

    /** Дата включения захвата источника регистрации (telegram_users.acquisition_source). */
    private const ACQUISITION_TRACKED_SINCE = '2026-06-21';

    /** Дата активации инжектируема для тестов (relative-to-NOW сиды → time-stable asserts). */
    public function __construct(private string $e5Activation = self::E5_ACTIVATION)
    {
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        return [
            'summary'    => $this->summary(),
            'funnel_all' => $this->funnel(null),
            'funnel_30d' => $this->funnel(30),
            'levels'     => $this->levelBuckets(),
            'weekly'     => $this->weeklyCohorts(8),
            'anomalies'  => $this->anomalies(),
            'quests'     => $this->questSlice(),
            'e5'         => $this->e5Slice(),
            'onboarding' => $this->onboardingChainSlice(),
            'faction'    => $this->factionConversionSlice(),
            'acquisition' => $this->acquisitionSlice(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Сводка: аудитория, достижимость, активность по движению.
     *
     * @return array<string,int>
     */
    public function summary(): array
    {
        $tg = $this->row('SELECT COUNT(*) t, SUM(blocked_at IS NOT NULL) b FROM telegram_users');
        $ch = $this->row(
            'SELECT COUNT(*) t,
                    SUM(tu.blocked_at IS NULL) reachable
             FROM characters c LEFT JOIN telegram_users tu ON tu.id = c.telegram_user_id'
        );

        $active = [];
        foreach ([1, 7, 14, 30] as $days) {
            $r = $this->row(
                "SELECT COUNT(DISTINCT character_id) n FROM explored_cells
                 WHERE created_at >= NOW() - INTERVAL {$days} DAY"
            );
            $active['active_' . $days . 'd'] = $this->n($r['n'] ?? 0);
        }

        return [
            'tg_total'        => $this->n($tg['t'] ?? 0),
            'tg_blocked'      => $this->n($tg['b'] ?? 0),
            'chars_total'     => $this->n($ch['t'] ?? 0),
            'chars_reachable' => $this->n($ch['reachable'] ?? 0),
        ] + $active;
    }

    /**
     * Воронка: регистрация → движение → база → L5 → L10 → фракция → L25.
     * $cohortDays = null → за всё время; N → только чары, созданные за последние N дней.
     *
     * @return list<array{key:string,label:string,count:int,pct:float}>
     */
    public function funnel(?int $cohortDays): array
    {
        $where = $cohortDays === null
            ? ''
            : ' AND c.created_at >= NOW() - INTERVAL ' . max(1, $cohortDays) . ' DAY';

        $r = $this->row(
            "SELECT COUNT(*) total,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id)) moved,
                    SUM(EXISTS(SELECT 1 FROM claimed_cells cc WHERE cc.character_id = c.id AND cc.status = 'active')) base,
                    SUM(c.level >= 5)  l5,
                    SUM(c.level >= 10) l10,
                    SUM(EXISTS(SELECT 1 FROM character_factions cf WHERE cf.character_id = c.id AND cf.faction_id BETWEEN 1 AND 4)) faction,
                    SUM(c.level >= 25) l25
             FROM characters c WHERE 1=1 {$where}"
        );

        $total = $this->n($r['total'] ?? 0);
        $steps = [
            ['key' => 'total',   'label' => 'Регистрация (персонаж создан)', 'count' => $total],
            ['key' => 'moved',   'label' => 'Сделал хотя бы один шаг',       'count' => $this->n($r['moved'] ?? 0)],
            ['key' => 'base',    'label' => 'Активная база',                  'count' => $this->n($r['base'] ?? 0)],
            ['key' => 'l5',      'label' => 'Достиг L5',                      'count' => $this->n($r['l5'] ?? 0)],
            ['key' => 'l10',     'label' => 'Достиг L10 (анлок фракции)',     'count' => $this->n($r['l10'] ?? 0)],
            ['key' => 'faction', 'label' => 'Выбрал фракцию',                 'count' => $this->n($r['faction'] ?? 0)],
            ['key' => 'l25',     'label' => 'Достиг L25 (мидгейм)',           'count' => $this->n($r['l25'] ?? 0)],
        ];

        $out = [];
        foreach ($steps as $s) {
            $out[] = $s + ['pct' => $total > 0 ? round(100 * $s['count'] / $total, 1) : 0.0];
        }
        return $out;
    }

    /**
     * Распределение по уровневым корзинам.
     *
     * @return list<array{bucket:string,chars:int}>
     */
    public function levelBuckets(): array
    {
        $rows = $this->rows(
            "SELECT CASE WHEN level <= 1 THEN 'L1' WHEN level < 5 THEN 'L2-4' WHEN level < 10 THEN 'L5-9'
                         WHEN level < 25 THEN 'L10-24' WHEN level < 50 THEN 'L25-49'
                         WHEN level < 100 THEN 'L50-99' ELSE 'L100+' END bucket,
                    MIN(level) lv, COUNT(*) chars
             FROM characters GROUP BY bucket ORDER BY lv"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['bucket' => $this->s($r['bucket'] ?? ''), 'chars' => $this->n($r['chars'] ?? 0)];
        }
        return $out;
    }

    /**
     * Недельные когорты регистраций: пришли / сделали шаг / вернулись после D1 / после D7 / взяли базу.
     *
     * @return list<array{week:string,regs:int,moved:int,back_d1:int,back_d7:int,with_base:int}>
     */
    public function weeklyCohorts(int $weeks): array
    {
        $weeks = max(1, min(26, $weeks));
        $rows  = $this->rows(
            "SELECT DATE_FORMAT(c.created_at, '%x-W%v') wk, COUNT(*) regs,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id)) moved,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at >= c.created_at + INTERVAL 1 DAY)) back_d1,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at >= c.created_at + INTERVAL 7 DAY)) back_d7,
                    SUM(EXISTS(SELECT 1 FROM claimed_cells cc WHERE cc.character_id = c.id)) with_base
             FROM characters c
             WHERE c.created_at >= NOW() - INTERVAL " . ($weeks * 7) . " DAY
             GROUP BY wk ORDER BY wk"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'week'      => $this->s($r['wk'] ?? ''),
                'regs'      => $this->n($r['regs'] ?? 0),
                'moved'     => $this->n($r['moved'] ?? 0),
                'back_d1'   => $this->n($r['back_d1'] ?? 0),
                'back_d7'   => $this->n($r['back_d7'] ?? 0),
                'with_base' => $this->n($r['with_base'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Аномалии: легаси-ловушка клетки 1 (респавн-fallback) и «застрявшие» L1.
     *
     * @return array<string,int>
     */
    public function anomalies(): array
    {
        $cell1 = $this->row(
            'SELECT COUNT(*) t, SUM(tu.blocked_at IS NOT NULL) b
             FROM characters c LEFT JOIN telegram_users tu ON tu.id = c.telegram_user_id
             WHERE c.cell_number = 1'
        );
        $stuck = $this->row(
            'SELECT COUNT(*) t FROM characters c
             WHERE c.level = 1 AND c.created_at < NOW() - INTERVAL 14 DAY
               AND NOT EXISTS (SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at >= NOW() - INTERVAL 14 DAY)'
        );

        return [
            'cell1_chars'   => $this->n($cell1['t'] ?? 0),
            'cell1_blocked' => $this->n($cell1['b'] ?? 0),
            'stuck_l1'      => $this->n($stuck['t'] ?? 0),
        ];
    }

    /**
     * Срез квестов ADR-088 с момента активации: NEW (extended) vs OLD (baseline).
     *
     * @return array<string,array<string,int>>
     */
    public function questSlice(): array
    {
        $rows = $this->rows(
            "SELECT CASE WHEN q.objective_type IN " . self::EXTENDED_TYPES . " THEN 'new' ELSE 'old' END bucket,
                    COUNT(*) started, COALESCE(SUM(qs.is_completed), 0) completed,
                    COUNT(DISTINCT qs.character_id) players
             FROM quest_steps qs JOIN quests q ON q.id = qs.quest_id
             WHERE qs.created_at >= '" . self::QUEST_ACTIVATION . "'
             GROUP BY bucket"
        );

        $out = [
            'new' => ['started' => 0, 'completed' => 0, 'players' => 0],
            'old' => ['started' => 0, 'completed' => 0, 'players' => 0],
        ];
        foreach ($rows as $r) {
            $bucket = $this->s($r['bucket'] ?? '');
            if ($bucket !== 'new' && $bucket !== 'old') {
                continue;
            }
            $out[$bucket] = [
                'started'   => $this->n($r['started'] ?? 0),
                'completed' => $this->n($r['completed'] ?? 0),
                'players'   => $this->n($r['players'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * E5 Ф4 (ADR-104) — хронометраж-срез «первые 30 минут»: когорта ДО активации E5
     * (E5_BEFORE_DAYS дней) vs ПОСЛЕ. Меряет первый шаг (время до него — единственный
     * полный таймлайн = explored_cells), adoption-механики E5 (набор/удача — флаги
     * action_log), раннюю экономику (продажи — форензик-лог v0.51.389) и возвраты D1/D7.
     *
     * @return array{activation: string, before: array<string,int|float|null>, after: array<string,int|float|null>}
     */
    public function e5Slice(): array
    {
        return [
            'activation' => $this->e5Activation,
            'before'     => $this->e5Cohort(false),
            'after'      => $this->e5Cohort(true),
        ];
    }

    /**
     * Метрики одной когорты E5-среза.
     *
     * @return array<string,int|float|null>
     */
    private function e5Cohort(bool $after): array
    {
        $act   = Database::connect()->escape($this->e5Activation);
        $where = $after
            ? "c.created_at >= {$act}"
            : 'c.created_at >= ' . $act . ' - INTERVAL ' . self::E5_BEFORE_DAYS . ' DAY AND c.created_at < ' . $act;

        $r = $this->row(
            "SELECT COUNT(*) regs,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id)) moved,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at <= c.created_at + INTERVAL 30 MINUTE)) moved_30m,
                    SUM(EXISTS(SELECT 1 FROM action_log a WHERE a.character_id = c.id
                               AND a.action_name = 'STARTER_KIT_GRANTED')) kit,
                    SUM(EXISTS(SELECT 1 FROM action_log a WHERE a.character_id = c.id
                               AND a.action_name = 'LUCKY_FIND_FIRST')) lucky,
                    SUM(EXISTS(SELECT 1 FROM action_log a WHERE a.character_id = c.id
                               AND a.action_name IN ('SELL_RESOURCE','BULK_SELL'))) sellers,
                    SUM(c.level >= 2) l2plus,
                    SUM(c.created_at <= NOW() - INTERVAL 1 DAY) d1_eligible,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at >= c.created_at + INTERVAL 1 DAY)) back_d1,
                    SUM(c.created_at <= NOW() - INTERVAL 7 DAY) d7_eligible,
                    SUM(EXISTS(SELECT 1 FROM explored_cells e WHERE e.character_id = c.id
                               AND e.created_at >= c.created_at + INTERVAL 7 DAY)) back_d7
             FROM characters c WHERE {$where}"
        );

        // Хронометраж: минуты от регистрации до ПЕРВОГО шага (per-char) → медиана в PHP.
        $delays = [];
        foreach ($this->rows(
            "SELECT TIMESTAMPDIFF(MINUTE, c.created_at, MIN(e.created_at)) m
             FROM characters c JOIN explored_cells e ON e.character_id = c.id
             WHERE {$where} GROUP BY c.id, c.created_at"
        ) as $row) {
            $m = $row['m'] ?? null;
            if (is_numeric($m)) {
                $delays[] = (float) max(0, (int) $m);
            }
        }

        $regs     = $this->n($r['regs'] ?? 0);
        $moved    = $this->n($r['moved'] ?? 0);
        $moved30m = $this->n($r['moved_30m'] ?? 0);

        return [
            'regs'                  => $regs,
            'moved'                 => $moved,
            'moved_pct'             => $regs > 0 ? round(100 * $moved / $regs, 1) : 0.0,
            'moved_30m'             => $moved30m,
            'moved_30m_pct'         => $regs > 0 ? round(100 * $moved30m / $regs, 1) : 0.0,
            'median_first_move_min' => self::median($delays),
            'kit'                   => $this->n($r['kit'] ?? 0),
            'lucky'                 => $this->n($r['lucky'] ?? 0),
            'sellers'               => $this->n($r['sellers'] ?? 0),
            'l2plus'                => $this->n($r['l2plus'] ?? 0),
            'd1_eligible'           => $this->n($r['d1_eligible'] ?? 0),
            'back_d1'               => $this->n($r['back_d1'] ?? 0),
            'd7_eligible'           => $this->n($r['d7_eligible'] ?? 0),
            'back_d7'               => $this->n($r['back_d7'] ?? 0),
        ];
    }

    /**
     * Медиана списка значений; null для пустого. Чистая (unit-тестируется).
     *
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : round(($values[$mid - 1] + $values[$mid]) / 2, 1);
    }

    /**
     * E4 (ROADMAP-100-SESSIONS) — срез обучающей цепочки «Первые шаги выжившего» (ADR-103 Слой 2).
     *
     * Per-step воронка: сколько чаров ДОШЛО до шага (есть строка quest_steps) vs ПРОШЛО его
     * (is_completed=1). Шаги цепочки = quests с title_en LIKE 'OnbStep%'; advanceChain назначает
     * следующий шаг только при завершении текущего → reached каскадно убывает, drop-off виден между
     * reached и completed внутри шага. Порядок шагов берётся из каталога (анти-дрейф БД↔код).
     *
     * Плюс adoption Слоя 1 (контекстные one-shot подсказки): сколько чаров получило каждую
     * подсказку (action_log action_name LIKE 'OnbHint%' / событие открытия базы 'OnbEvent%').
     *
     * @return array{
     *   enabled: bool,
     *   started: int,
     *   graduated: int,
     *   steps: list<array{title_en:string,label:string,reached:int,completed:int,pct:float}>,
     *   hints: list<array{action_name:string,chars:int}>
     * }
     */
    public function onboardingChainSlice(): array
    {
        $titles = array_map(static fn (array $s): string => $s['title_en'], OnboardingChainCatalog::steps());
        $ruByEn = [];
        foreach (OnboardingChainCatalog::steps() as $s) {
            $ruByEn[$s['title_en']] = $s['title_ru'];
        }
        $order = "FIELD(q.title_en, " . implode(',', array_map(
            static fn (string $t): string => "'" . $t . "'",
            $titles
        )) . ')';

        $rows = $this->rows(
            "SELECT q.title_en,
                    COUNT(DISTINCT qs.character_id) reached,
                    COUNT(DISTINCT CASE WHEN qs.is_completed = 1 THEN qs.character_id END) completed
             FROM quest_steps qs JOIN quests q ON q.id = qs.quest_id
             WHERE q.title_en LIKE '" . OnboardingChainCatalog::PREFIX . "%'
             GROUP BY q.title_en
             ORDER BY {$order}"
        );

        $steps     = [];
        $started   = 0;
        $graduated = 0;
        foreach ($rows as $r) {
            $titleEn = $this->s($r['title_en'] ?? '');
            $reached   = $this->n($r['reached'] ?? 0);
            $completed = $this->n($r['completed'] ?? 0);
            if ($titleEn === OnboardingChainCatalog::ROOT) {
                $started = $reached;
            }
            if ($titleEn === OnboardingChainCatalog::STEP_LEVEL5) {
                $graduated = $completed;
            }
            $steps[] = [
                'title_en'  => $titleEn,
                'label'     => $ruByEn[$titleEn] ?? $titleEn,
                'reached'   => $reached,
                'completed' => $completed,
                'pct'       => $reached > 0 ? round(100 * $completed / $reached, 1) : 0.0,
            ];
        }

        $hints = [];
        foreach ($this->rows(
            "SELECT action_name, COUNT(DISTINCT character_id) chars
             FROM action_log
             WHERE action_name LIKE 'OnbHint%' OR action_name LIKE 'OnbEvent%'
             GROUP BY action_name ORDER BY chars DESC"
        ) as $r) {
            $hints[] = [
                'action_name' => $this->s($r['action_name'] ?? ''),
                'chars'       => $this->n($r['chars'] ?? 0),
            ];
        }

        return [
            'enabled'   => $this->settingBool('onboarding.quest_chain.enabled'),
            'started'   => $started,
            'graduated' => $graduated,
            'steps'     => $steps,
            'hints'     => $hints,
        ];
    }

    /**
     * E7 (ROADMAP-100-SESSIONS) — срез конверсии в фракцию (цель E7: 3.4% → ≥30%).
     *
     * Ключевой методологический акцент: конверсию мерим ДВУМЯ знаменателями —
     *   • от ВСЕХ чаров (`conversion_all_pct`) = цель роадмапа 3.4%→30%;
     *   • от ДОСТИГШИХ L10 (`conversion_eligible_pct`) = здоровье самого диалога выбора.
     * Срез 2026-06-11 вскрыл: eligible-конверсия ~62% (диалог работает), а узкое место —
     * доведение до L10 (анлок фракций) + ранняя мотивация. `chosen_since_nudge` мерит эффект
     * ADR-097 (стимул 1000💰 + value-prop) с активации.
     *
     * faction_id 1-4 = реальные фракции; 5 = плейсхолдер «не выбрана» (крон создал, выбора нет).
     *
     * @return array{
     *   total:int, l10_eligible:int, chosen:int, unchosen_at_l10:int,
     *   conversion_all_pct:float, conversion_eligible_pct:float,
     *   chosen_since_nudge:int, nudge_activation:string,
     *   by_faction: list<array{faction_id:int,name:string,chars:int}>
     * }
     */
    public function factionConversionSlice(): array
    {
        $r = $this->row(
            "SELECT (SELECT COUNT(*) FROM characters) total,
                    (SELECT COUNT(*) FROM characters WHERE level >= 10) l10,
                    (SELECT COUNT(*) FROM character_factions WHERE faction_id BETWEEN 1 AND 4) chosen,
                    (SELECT COUNT(*) FROM characters c WHERE c.level >= 10
                        AND NOT EXISTS(SELECT 1 FROM character_factions cf
                            WHERE cf.character_id = c.id AND cf.faction_id BETWEEN 1 AND 4)) unchosen_l10,
                    (SELECT COUNT(*) FROM character_factions WHERE faction_id BETWEEN 1 AND 4
                        AND joined_at >= '" . self::FACTION_NUDGE_ACTIVATION . "') chosen_since"
        );

        $total    = $this->n($r['total'] ?? 0);
        $l10      = $this->n($r['l10'] ?? 0);
        $chosen   = $this->n($r['chosen'] ?? 0);

        $byFaction = [];
        foreach ($this->rows(
            'SELECT faction_id, COUNT(*) chars FROM character_factions
             WHERE faction_id BETWEEN 1 AND 4 GROUP BY faction_id ORDER BY faction_id'
        ) as $row) {
            $fid = $this->n($row['faction_id'] ?? 0);
            $byFaction[] = [
                'faction_id' => $fid,
                'name'       => self::FACTION_NAMES[$fid] ?? ('#' . $fid),
                'chars'      => $this->n($row['chars'] ?? 0),
            ];
        }

        return [
            'total'                   => $total,
            'l10_eligible'            => $l10,
            'chosen'                  => $chosen,
            'unchosen_at_l10'         => $this->n($r['unchosen_l10'] ?? 0),
            'conversion_all_pct'      => $total > 0 ? round(100 * $chosen / $total, 1) : 0.0,
            'conversion_eligible_pct' => $l10 > 0 ? round(100 * $chosen / $l10, 1) : 0.0,
            'chosen_since_nudge'      => $this->n($r['chosen_since'] ?? 0),
            'nudge_activation'        => self::FACTION_NUDGE_ACTIVATION,
            'by_faction'              => $byFaction,
        ];
    }

    /**
     * Атрибуция интейка — регистрации по источнику (`telegram_users.acquisition_source`,
     * first-touch из payload `/start src_*`). NULL/'' = органика/прямой/до-захвата. Захват
     * включён 2026-06-21 → исторические NULL ожидаемы, разбивка наполняется свежими регистрациями.
     *
     * Drift-safe (try/catch → пустой срез): на стейл-дампе без колонки не роняет дашборд.
     *
     * @return array{tracked_since:string, total:int, captured:int, organic:int,
     *   sources: list<array{source:string,total:int,last14d:int,last30d:int,last_reg:string}>}
     */
    public function acquisitionSlice(): array
    {
        $empty = [
            'tracked_since' => self::ACQUISITION_TRACKED_SINCE,
            'total'    => 0,
            'captured' => 0,
            'organic'  => 0,
            'sources'  => [],
        ];

        try {
            $head = $this->row(
                "SELECT COUNT(*) total,
                        SUM(acquisition_source IS NOT NULL AND acquisition_source <> '') captured,
                        SUM(acquisition_source IS NULL OR acquisition_source = '') organic
                 FROM telegram_users"
            );
            $rows = $this->rows(
                "SELECT acquisition_source src, COUNT(*) total,
                        SUM(created_at >= NOW() - INTERVAL 14 DAY) last14d,
                        SUM(created_at >= NOW() - INTERVAL 30 DAY) last30d,
                        MAX(created_at) last_reg
                 FROM telegram_users
                 WHERE acquisition_source IS NOT NULL AND acquisition_source <> ''
                 GROUP BY acquisition_source
                 ORDER BY total DESC, last_reg DESC
                 LIMIT 50"
            );
        } catch (\Throwable $e) {
            log_message('error', '[FunnelAnalytics] acquisitionSlice: ' . $e->getMessage());
            return $empty;
        }

        $sources = [];
        foreach ($rows as $r) {
            $sources[] = [
                'source'   => $this->s($r['src'] ?? ''),
                'total'    => $this->n($r['total'] ?? 0),
                'last14d'  => $this->n($r['last14d'] ?? 0),
                'last30d'  => $this->n($r['last30d'] ?? 0),
                'last_reg' => $this->s($r['last_reg'] ?? ''),
            ];
        }

        return [
            'tracked_since' => self::ACQUISITION_TRACKED_SINCE,
            'total'    => $this->n($head['total'] ?? 0),
            'captured' => $this->n($head['captured'] ?? 0),
            'organic'  => $this->n($head['organic'] ?? 0),
            'sources'  => $sources,
        ];
    }

    /** Чтение bool-флага из game_settings (read-only, для контекста killswitch в дашборде). */
    private function settingBool(string $key): bool
    {
        $r = $this->row(
            "SELECT value_bool FROM game_settings WHERE setting_key = " . Database::connect()->escape($key) . " LIMIT 1"
        );

        return $this->n($r['value_bool'] ?? 0) === 1;
    }

    // ── db helpers (паттерн CraftingEconomyService) ───────────────────────────

    /** @return array<string,mixed> */
    private function row(string $sql): array
    {
        $q = Database::connect()->query($sql);
        if (! $q instanceof BaseResult) {
            return [];
        }
        $row = $q->getRowArray();
        return is_array($row) ? $row : [];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $q = Database::connect()->query($sql);
        if (! $q instanceof BaseResult) {
            return [];
        }
        return array_values($q->getResultArray());
    }

    private function n(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
