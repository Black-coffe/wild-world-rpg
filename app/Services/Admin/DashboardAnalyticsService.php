<?php

declare(strict_types=1);

namespace App\Services\Admin;

use CodeIgniter\Database\BaseResult;
use Config\Database;
use Throwable;

/**
 * Агрегатор главного admin-дашборда `/admin` (логотип → панель управления).
 *
 * Read-only: только агрегирующие SELECT'ы. Собирает обзор по ВСЕМ направлениям
 * проекта — игроки, рост/активность, уровни, биомы, фракции, золото/экономика,
 * бои/PvP, крафт, постройки, текущие задания, мир, лидерборды — чтобы владелец
 * одним взглядом видел пульс игры. Дашборд рендерит данные графиками (ApexCharts,
 * уже подключён в layout `templates/dashboard`).
 *
 * 🛡 Drift-safe: каждый запрос обёрнут в try/catch (см. row()/rows()). На проде есть
 * все колонки/таблицы (`telegram_users.blocked_at`, `battle_logs`, `pvp_ladder`),
 * на стейл-дампах их может не быть — тогда соответствующая метрика деградирует к
 * нулю/пустоте, а страница всё равно рендерится (а не падает 500). Активность
 * меряется ДВИЖЕНИЕМ (`explored_cells.created_at`) — единственный полный таймлайн
 * player-действий (тот же выбор, что в FunnelAnalyticsService).
 */
final class DashboardAnalyticsService
{
    /** Глубина окна тренда (дней) по умолчанию для time-series графиков. */
    private const TREND_DAYS = 30;

    /** Допустимые окна тренда (переключатель периода в дашборде). */
    public const ALLOWED_TRENDS = [7, 30, 90];

    /** faction_id 1-4 — реальные фракции (5 = плейсхолдер «не выбрана»). */
    private const FACTION_RANGE = 'BETWEEN 1 AND 4';

    /**
     * @param int $trendDays окно time-series (7/30/90); невалидное → дефолт 30.
     *                       Влияет только на тренды (рост/бои/рынок), не на KPI/срезы.
     * @return array<string,mixed>
     */
    public function dashboard(int $trendDays = self::TREND_DAYS): array
    {
        $trendDays = in_array($trendDays, self::ALLOWED_TRENDS, true) ? $trendDays : self::TREND_DAYS;

        return [
            'kpi'              => $this->kpi(),
            'growth'          => $this->growthTrend($trendDays),
            'levels'          => $this->levelBuckets(),
            'biomes'          => $this->biomeDistribution(),
            'factions'        => $this->factionDistribution(),
            'gold_buckets'    => $this->goldBuckets(),
            'battles'         => $this->battlesSlice($trendDays),
            'pvp_ladder'      => $this->pvpLadderTop(),
            'economy'         => $this->economyTrend($trendDays),
            'top_crafted'     => $this->topCrafted(),
            'top_resources'   => $this->topResources(),
            'buildings'       => $this->buildingsByType(),
            'tasks'           => $this->activeTasksByType(),
            'world'           => $this->worldSnapshot(),
            'top_players'     => $this->topPlayers(),
            'top_rich'        => $this->topRich(),
            'trend_days'      => $trendDays,
            'generated_at'    => date('Y-m-d H:i:s'),
        ];
    }

    // ── KPI hero ──────────────────────────────────────────────────────────────

    /**
     * Верхняя плашка ключевых чисел. Каждое — отдельный устойчивый запрос с
     * безопасным дефолтом, чтобы отсутствие одной таблицы не обнуляло остальные.
     *
     * @return array<string,int|float>
     */
    public function kpi(): array
    {
        $ch = $this->row('SELECT COUNT(*) t, COALESCE(SUM(gold),0) gold, ROUND(AVG(level),1) avg_lvl, MAX(level) max_lvl FROM characters');
        $tg = $this->row('SELECT COUNT(*) t FROM telegram_users');

        $charsTotal = $this->n($ch['t'] ?? 0);

        // Достижимость: blocked_at есть только на проде → guard → fallback к total.
        $reach = $this->row(
            'SELECT SUM(tu.blocked_at IS NULL) r FROM characters c
             LEFT JOIN telegram_users tu ON tu.id = c.telegram_user_id'
        );
        $reachable = $reach === [] ? $charsTotal : $this->n($reach['r'] ?? 0);

        return [
            'chars_total'     => $charsTotal,
            'tg_total'        => $this->n($tg['t'] ?? 0),
            'reachable'       => $reachable,
            'active_24h'      => $this->activeMovers(1),
            'active_7d'       => $this->activeMovers(7),
            'active_30d'      => $this->activeMovers(30),
            'new_7d'          => $this->newChars(7),
            'new_30d'         => $this->newChars(30),
            'gold_total'      => $this->f($ch['gold'] ?? 0),
            'avg_level'       => $this->f($ch['avg_lvl'] ?? 0),
            'max_level'       => $this->n($ch['max_lvl'] ?? 0),
            'battles_total'   => $this->scalar('SELECT COUNT(*) c FROM battle_logs', 'c'),
            'bases_active'    => $this->scalar("SELECT COUNT(*) c FROM claimed_cells WHERE status='active'", 'c'),
            'buildings_total' => $this->scalar('SELECT COALESCE(SUM(amount),0) c FROM character_buildings', 'c'),
        ];
    }

    private function activeMovers(int $days): int
    {
        $r = $this->row(
            "SELECT COUNT(DISTINCT character_id) n FROM explored_cells
             WHERE created_at >= NOW() - INTERVAL " . max(1, $days) . ' DAY'
        );
        return $this->n($r['n'] ?? 0);
    }

    private function newChars(int $days): int
    {
        $r = $this->row(
            'SELECT COUNT(*) n FROM characters WHERE created_at >= NOW() - INTERVAL ' . max(1, $days) . ' DAY'
        );
        return $this->n($r['n'] ?? 0);
    }

    // ── Рост и активность (time-series) ───────────────────────────────────────

    /**
     * Тренд за N дней: новые персонажи vs активные (по движению). Zero-filled —
     * непрерывная ось дат для линейного графика.
     *
     * @return array{labels:list<string>,regs:list<int>,active:list<int>}
     */
    public function growthTrend(int $days): array
    {
        $regsMap = $this->mapByDay(
            "SELECT DATE(created_at) d, COUNT(*) c FROM characters
             WHERE created_at >= NOW() - INTERVAL {$days} DAY GROUP BY d"
        );
        $actMap = $this->mapByDay(
            "SELECT DATE(created_at) d, COUNT(DISTINCT character_id) c FROM explored_cells
             WHERE created_at >= NOW() - INTERVAL {$days} DAY GROUP BY d"
        );

        $labels = $regs = $active = [];
        foreach ($this->dayKeys($days) as $day) {
            $labels[] = $this->shortDay($day);
            $regs[]   = $regsMap[$day] ?? 0;
            $active[] = $actMap[$day] ?? 0;
        }

        return ['labels' => $labels, 'regs' => $regs, 'active' => $active];
    }

    // ── Срезы-распределения ───────────────────────────────────────────────────

    /** @return list<array{bucket:string,chars:int}> */
    public function levelBuckets(): array
    {
        $rows = $this->rows(
            "SELECT CASE WHEN level <= 1 THEN 'L1' WHEN level < 5 THEN 'L2-4' WHEN level < 10 THEN 'L5-9'
                         WHEN level < 25 THEN 'L10-24' WHEN level < 50 THEN 'L25-49'
                         WHEN level < 100 THEN 'L50-99' ELSE 'L100+' END bucket,
                    MIN(level) lv, COUNT(*) chars
             FROM characters GROUP BY bucket ORDER BY lv"
        );
        return array_map(fn (array $r): array => [
            'bucket' => $this->s($r['bucket'] ?? ''),
            'chars'  => $this->n($r['chars'] ?? 0),
        ], $rows);
    }

    /** @return list<array{name:string,chars:int}> */
    public function biomeDistribution(): array
    {
        $rows = $this->rows(
            "SELECT COALESCE(b.name, '— без биома —') name, COUNT(*) chars
             FROM characters c LEFT JOIN biomes b ON b.id = c.biome_id
             GROUP BY c.biome_id ORDER BY chars DESC LIMIT 12"
        );
        return array_map(fn (array $r): array => [
            'name'  => $this->s($r['name'] ?? ''),
            'chars' => $this->n($r['chars'] ?? 0),
        ], $rows);
    }

    /** @return list<array{name:string,chars:int}> */
    public function factionDistribution(): array
    {
        $rows = $this->rows(
            'SELECT f.name, COUNT(*) chars FROM character_factions cf
             JOIN factions f ON f.id = cf.faction_id
             WHERE cf.faction_id ' . self::FACTION_RANGE . '
             GROUP BY cf.faction_id ORDER BY chars DESC'
        );
        return array_map(fn (array $r): array => [
            'name'  => $this->s($r['name'] ?? ''),
            'chars' => $this->n($r['chars'] ?? 0),
        ], $rows);
    }

    /** @return list<array{bucket:string,chars:int}> */
    public function goldBuckets(): array
    {
        $rows = $this->rows(
            "SELECT bucket, MIN(g) ord, COUNT(*) chars FROM (
                SELECT CASE
                    WHEN gold IS NULL OR gold <= 0 THEN '0'
                    WHEN gold < 1000     THEN '1–999'
                    WHEN gold < 10000    THEN '1K–10K'
                    WHEN gold < 100000   THEN '10K–100K'
                    WHEN gold < 1000000  THEN '100K–1M'
                    ELSE '1M+' END bucket,
                    COALESCE(gold,0) g
                FROM characters) t
             GROUP BY bucket ORDER BY ord"
        );
        return array_map(fn (array $r): array => [
            'bucket' => $this->s($r['bucket'] ?? ''),
            'chars'  => $this->n($r['chars'] ?? 0),
        ], $rows);
    }

    // ── Бои / PvP ─────────────────────────────────────────────────────────────

    /**
     * Срез боёв из `battle_logs` (есть на проде; guard для стейл-дампов).
     *
     * @return array{total:int,by_type:list<array{type:string,count:int}>,labels:list<string>,daily:list<int>}
     */
    public function battlesSlice(int $days): array
    {
        $byType = array_map(fn (array $r): array => [
            'type'  => strtoupper($this->s($r['t'] ?? '?')),
            'count' => $this->n($r['c'] ?? 0),
        ], $this->rows('SELECT battle_type t, COUNT(*) c FROM battle_logs GROUP BY battle_type ORDER BY c DESC'));

        $dailyMap = $this->mapByDay(
            "SELECT DATE(created_at) d, COUNT(*) c FROM battle_logs
             WHERE created_at >= NOW() - INTERVAL {$days} DAY GROUP BY d"
        );
        $labels = $daily = [];
        foreach ($this->dayKeys($days) as $day) {
            $labels[] = $this->shortDay($day);
            $daily[]  = $dailyMap[$day] ?? 0;
        }

        return [
            'total'   => $this->scalar('SELECT COUNT(*) c FROM battle_logs', 'c'),
            'by_type' => $byType,
            'labels'  => $labels,
            'daily'   => $daily,
        ];
    }

    /** @return list<array{name:string,points:int,wins:int,losses:int}> */
    public function pvpLadderTop(): array
    {
        $rows = $this->rows(
            'SELECT c.name, pl.points,
                    (pl.duel_wins + pl.pvp_wins) wins,
                    (pl.duel_losses + pl.pvp_losses) losses
             FROM pvp_ladder pl JOIN characters c ON c.id = pl.character_id
             ORDER BY pl.points DESC, wins DESC LIMIT 10'
        );
        return array_map(fn (array $r): array => [
            'name'   => $this->s($r['name'] ?? '—'),
            'points' => $this->n($r['points'] ?? 0),
            'wins'   => $this->n($r['wins'] ?? 0),
            'losses' => $this->n($r['losses'] ?? 0),
        ], $rows);
    }

    // ── Экономика ─────────────────────────────────────────────────────────────

    /**
     * Тренд рынка за N дней: оборот покупок vs продаж (золото).
     *
     * @return array{labels:list<string>,buy:list<int>,sell:list<int>}
     */
    public function economyTrend(int $days): array
    {
        $rows = $this->rows(
            "SELECT DATE(transaction_date) d, type, ROUND(SUM(price * quantity)) v
             FROM transactions
             WHERE transaction_date >= NOW() - INTERVAL {$days} DAY
             GROUP BY d, type"
        );
        $buyMap = $sellMap = [];
        foreach ($rows as $r) {
            $day = $this->s($r['d'] ?? '');
            $val = $this->n($r['v'] ?? 0);
            if ($this->s($r['type'] ?? '') === 'buy') {
                $buyMap[$day] = $val;
            } else {
                $sellMap[$day] = $val;
            }
        }

        $labels = $buy = $sell = [];
        foreach ($this->dayKeys($days) as $day) {
            $labels[] = $this->shortDay($day);
            $buy[]    = $buyMap[$day] ?? 0;
            $sell[]   = $sellMap[$day] ?? 0;
        }

        return ['labels' => $labels, 'buy' => $buy, 'sell' => $sell];
    }

    /** @return list<array{name:string,qty:int}> */
    public function topCrafted(): array
    {
        $rows = $this->rows(
            'SELECT ci.name_rus name, SUM(l.quantity) q
             FROM crafted_items_log l JOIN crafted_items ci ON ci.id = l.crafted_item_id
             GROUP BY l.crafted_item_id ORDER BY q DESC LIMIT 10'
        );
        return array_map(fn (array $r): array => [
            'name' => $this->s($r['name'] ?? ''),
            'qty'  => $this->n($r['q'] ?? 0),
        ], $rows);
    }

    /** @return list<array{name:string,qty:int}> Складские запасы рынка (resources_bank). */
    public function topResources(): array
    {
        $rows = $this->rows(
            'SELECT r.name, rb.current_quantity q
             FROM resources_bank rb JOIN resources r ON r.id = rb.resource_id
             ORDER BY rb.current_quantity DESC LIMIT 10'
        );
        return array_map(fn (array $r): array => [
            'name' => $this->s($r['name'] ?? ''),
            'qty'  => $this->n($r['q'] ?? 0),
        ], $rows);
    }

    // ── Мир / постройки / задания ─────────────────────────────────────────────

    /** @return list<array{type:string,count:int}> */
    public function buildingsByType(): array
    {
        $rows = $this->rows(
            'SELECT building_type, COALESCE(SUM(amount),0) c FROM character_buildings
             GROUP BY building_type ORDER BY c DESC'
        );
        return array_map(fn (array $r): array => [
            'type'  => $this->buildingTypeRu($this->s($r['building_type'] ?? '')),
            'count' => $this->n($r['c'] ?? 0),
        ], $rows);
    }

    /** @return list<array{type:string,count:int}> Снимок: чем игроки заняты прямо сейчас. */
    public function activeTasksByType(): array
    {
        $rows = $this->rows(
            "SELECT t.type, COUNT(*) c FROM character_tasks ct
             JOIN tasks t ON t.id = ct.task_id
             WHERE ct.status = 'in_work' GROUP BY t.type ORDER BY c DESC"
        );
        return array_map(fn (array $r): array => [
            'type'  => $this->s($r['type'] ?? ''),
            'count' => $this->n($r['c'] ?? 0),
        ], $rows);
    }

    /** @return array<string,int> */
    public function worldSnapshot(): array
    {
        return [
            'explored_cells'     => $this->scalar('SELECT COUNT(*) c FROM explored_cells', 'c'),
            'claimed_active'     => $this->scalar("SELECT COUNT(*) c FROM claimed_cells WHERE status='active'", 'c'),
            'world_objects'      => $this->scalar("SELECT COUNT(*) c FROM world_objects WHERE status='active'", 'c'),
            'events_active'      => $this->scalar("SELECT COUNT(*) c FROM active_events WHERE status='active'", 'c'),
            'crafts_total'       => $this->scalar('SELECT COALESCE(SUM(quantity),0) c FROM crafted_items_log', 'c'),
            'txns_total'         => $this->scalar('SELECT COUNT(*) c FROM transactions', 'c'),
            'quests_completed'   => $this->scalar('SELECT COUNT(*) c FROM quest_steps WHERE is_completed=1', 'c'),
            'map_cells'          => $this->scalar('SELECT COUNT(*) c FROM map', 'c'),
        ];
    }

    // ── Лидерборды ────────────────────────────────────────────────────────────

    /** @return list<array{name:string,level:int,gold:float,exp:float}> */
    public function topPlayers(): array
    {
        $rows = $this->rows(
            'SELECT name, level, gold, experience FROM characters
             ORDER BY level DESC, experience DESC LIMIT 10'
        );
        return array_map(fn (array $r): array => [
            'name'  => $this->s($r['name'] ?? '—'),
            'level' => $this->n($r['level'] ?? 0),
            'gold'  => $this->f($r['gold'] ?? 0),
            'exp'   => $this->f($r['experience'] ?? 0),
        ], $rows);
    }

    /** @return list<array{name:string,gold:float,level:int}> */
    public function topRich(): array
    {
        $rows = $this->rows(
            'SELECT name, gold, level FROM characters
             WHERE gold IS NOT NULL ORDER BY gold DESC LIMIT 10'
        );
        return array_map(fn (array $r): array => [
            'name'  => $this->s($r['name'] ?? '—'),
            'gold'  => $this->f($r['gold'] ?? 0),
            'level' => $this->n($r['level'] ?? 0),
        ], $rows);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildingTypeRu(string $type): string
    {
        return [
            'military'    => '⚔️ Военные',
            'residential' => '🏠 Жилые',
            'farming'     => '🌾 Фермерские',
            'resource'    => '💎 Ресурсные',
            'engineering' => '🛠️ Инженерные',
        ][$type] ?? ($type === '' ? '—' : $type);
    }

    /** Y-m-d → 'd.m' для подписи оси. */
    private function shortDay(string $ymd): string
    {
        $ts = strtotime($ymd);
        return $ts === false ? $ymd : date('d.m', $ts);
    }

    /**
     * Список последних N дат (Y-m-d) по возрастанию.
     *
     * @return list<string>
     */
    private function dayKeys(int $days): array
    {
        $out = [];
        for ($i = max(1, $days) - 1; $i >= 0; $i--) {
            $ts = strtotime("-{$i} days");
            if ($ts !== false) {
                $out[] = date('Y-m-d', $ts);
            }
        }
        return $out;
    }

    /**
     * Выполнить «d → c» агрегат и вернуть карту [date => int].
     *
     * @return array<string,int>
     */
    private function mapByDay(string $sql): array
    {
        $map = [];
        foreach ($this->rows($sql) as $r) {
            $map[$this->s($r['d'] ?? '')] = $this->n($r['c'] ?? 0);
        }
        return $map;
    }

    /** Одно целочисленное значение по ключу (с дефолтом 0 и guard'ом). */
    private function scalar(string $sql, string $key): int
    {
        $r = $this->row($sql);
        return $this->n($r[$key] ?? 0);
    }

    /**
     * Drift-safe одиночная строка. Любая ошибка БД (нет колонки/таблицы на стейл-
     * дампе, DBDebug) → []. Не роняет дашборд, метрика деградирует к дефолту.
     *
     * @return array<string,mixed>
     */
    private function row(string $sql): array
    {
        try {
            $q = Database::connect()->query($sql);
            if (! $q instanceof BaseResult) {
                return [];
            }
            $row = $q->getRowArray();
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Drift-safe набор строк (см. row()).
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            $q = Database::connect()->query($sql);
            if (! $q instanceof BaseResult) {
                return [];
            }
            return array_values($q->getResultArray());
        } catch (Throwable) {
            return [];
        }
    }

    private function n(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private function f(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
