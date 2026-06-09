<?php

declare(strict_types=1);

namespace App\Services\Admin;

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
