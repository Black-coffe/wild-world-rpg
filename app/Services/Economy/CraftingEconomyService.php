<?php

declare(strict_types=1);

namespace App\Services\Economy;

use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * V21 (ADR-053) — агрегатор экономики для admin-дашборда `/admin/crafting-economy`.
 *
 * Read-only: только агрегирующие SELECT'ы из живых таблиц. Возвращает plain-массивы
 * для JSON-эндпоинта и CSV-экспорта. Ничего не пишет, баланс не меняет.
 *
 * Период (from/to, YYYY-MM-DD) применяется к ВРЕМЕННЫ́М метрикам (оборот крафта,
 * топ крафтов, транзакции, крафт-счётчики KPI). Золото и ресурсы — ТЕКУЩИЙ снимок
 * (склад «сейчас»), период к ним неприменим. from/to валидируются (YYYY-MM-DD),
 * иначе fallback на последние 12 месяцев → защита от инъекций.
 */
final class CraftingEconomyService
{
    private const DEFAULT_MONTHS = 12;

    /**
     * Полный payload для дашборда.
     *
     * @return array<string,mixed>
     */
    public function dashboard(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);

        return [
            'range'              => ['from' => $from, 'to' => $to],
            'summary'            => $this->summary($from, $to),
            'gold_concentration' => $this->goldConcentration(10),
            'craft_volume'       => $this->craftVolumeByMonth($from, $to),
            'top_crafted'        => $this->topCraftedItems(15, $from, $to),
            'top_resources'      => $this->topResourcesHeld(15),
            'transactions'       => $this->transactionsByMonth($from, $to),
            'generated_at'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * KPI-снимок. Золото/игроки/ресурсы — текущие; крафт-счётчики — за период [from,to].
     *
     * @return array<string,int>
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $craftRange  = $this->between('created_at', $from, $to);

        $chars = $this->row('SELECT COUNT(*) c, COALESCE(SUM(gold),0) g FROM characters');
        $log   = $this->row("SELECT COUNT(*) c, COALESCE(SUM(quantity),0) q FROM crafted_items_log WHERE 1=1 {$craftRange}");
        $res   = $this->row('SELECT COALESCE(SUM(quantity),0) q FROM character_resources');
        $tx    = $this->row("SELECT COUNT(*) c FROM transactions WHERE 1=1 " . $this->between('transaction_date', $from, $to));

        $players = $this->n($chars['c'] ?? 0);
        $gold    = $this->n($chars['g'] ?? 0);

        return [
            'players'      => $players,
            'total_gold'   => $gold,
            'avg_gold'     => $players > 0 ? (int) round($gold / $players) : 0,
            'crafted_rows' => $this->n($log['c'] ?? 0),
            'crafted_qty'  => $this->n($log['q'] ?? 0),
            'resource_qty' => $this->n($res['q'] ?? 0),
            'transactions' => $this->n($tx['c'] ?? 0),
        ];
    }

    /**
     * Концентрация золота (текущий снимок, период неприменим).
     *
     * @return array<string,mixed>
     */
    public function goldConcentration(int $topN = 10): array
    {
        $total = $this->n($this->row('SELECT COALESCE(SUM(gold),0) g FROM characters')['g'] ?? 0);

        $topN    = max(1, min(50, $topN));
        $rows    = $this->rows("SELECT name, gold FROM characters WHERE gold > 0 ORDER BY gold DESC LIMIT {$topN}");
        $holders = [];
        $topSum  = 0;
        foreach ($rows as $r) {
            $g          = $this->n($r['gold'] ?? 0);
            $topSum    += $g;
            $holders[]  = ['name' => $this->s($r['name'] ?? '—'), 'gold' => $g];
        }

        return [
            'total'          => $total,
            'holders'        => $holders,
            'top_share_pct'  => $total > 0 && $holders !== [] ? round($holders[0]['gold'] / $total * 100, 1) : 0.0,
            'topn_share_pct' => $total > 0 ? round($topSum / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Объём крафта по месяцам за период (turnover).
     *
     * @return list<array{month:string,qty:int,count:int}>
     */
    public function craftVolumeByMonth(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $rows = $this->rows(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COALESCE(SUM(quantity),0) qty, COUNT(*) cnt
             FROM crafted_items_log WHERE 1=1 " . $this->between('created_at', $from, $to) . "
             GROUP BY ym ORDER BY ym"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['month' => $this->s($r['ym'] ?? ''), 'qty' => $this->n($r['qty'] ?? 0), 'count' => $this->n($r['cnt'] ?? 0)];
        }
        return $out;
    }

    /**
     * Топ скрафченных предметов за период.
     *
     * @return list<array{name:string,type:string,qty:int}>
     */
    public function topCraftedItems(int $topN = 15, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $topN = max(1, min(50, $topN));
        $rows = $this->rows(
            "SELECT ci.name_rus name, ci.type type, COALESCE(SUM(cil.quantity),0) qty
             FROM crafted_items_log cil JOIN crafted_items ci ON ci.id = cil.crafted_item_id
             WHERE 1=1 " . $this->between('cil.created_at', $from, $to) . "
             GROUP BY cil.crafted_item_id ORDER BY qty DESC LIMIT {$topN}"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => $this->s($r['name'] ?? '—'), 'type' => $this->s($r['type'] ?? ''), 'qty' => $this->n($r['qty'] ?? 0)];
        }
        return $out;
    }

    /**
     * Топ удерживаемых ресурсов (текущий снимок, период неприменим).
     *
     * @return list<array{name:string,qty:int}>
     */
    public function topResourcesHeld(int $topN = 15): array
    {
        $topN = max(1, min(50, $topN));
        $rows = $this->rows(
            "SELECT r.name name, COALESCE(SUM(cr.quantity),0) qty
             FROM character_resources cr JOIN resources r ON r.id = cr.id_resources
             GROUP BY cr.id_resources ORDER BY qty DESC LIMIT {$topN}"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => $this->s($r['name'] ?? '—'), 'qty' => $this->n($r['qty'] ?? 0)];
        }
        return $out;
    }

    /**
     * Транзакции по месяцам за период: объём buy/sell + средняя цена.
     *
     * @return list<array{month:string,buy:int,sell:int,avg_buy:int,avg_sell:int}>
     */
    public function transactionsByMonth(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $rows = $this->rows(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') ym, type, COUNT(*) cnt, ROUND(AVG(price)) avg_price
             FROM transactions WHERE 1=1 " . $this->between('transaction_date', $from, $to) . "
             GROUP BY ym, type ORDER BY ym"
        );
        $byMonth = [];
        foreach ($rows as $r) {
            $ym   = $this->s($r['ym'] ?? '');
            $type = $this->s($r['type'] ?? '');
            if ($ym === '') {
                continue;
            }
            if (! isset($byMonth[$ym])) {
                $byMonth[$ym] = ['month' => $ym, 'buy' => 0, 'sell' => 0, 'avg_buy' => 0, 'avg_sell' => 0];
            }
            if ($type === 'buy') {
                $byMonth[$ym]['buy']     = $this->n($r['cnt'] ?? 0);
                $byMonth[$ym]['avg_buy'] = $this->n($r['avg_price'] ?? 0);
            } elseif ($type === 'sell') {
                $byMonth[$ym]['sell']     = $this->n($r['cnt'] ?? 0);
                $byMonth[$ym]['avg_sell'] = $this->n($r['avg_price'] ?? 0);
            }
        }
        return array_values($byMonth);
    }

    // ── CSV (S30-паттерн) ────────────────────────────────────────────────────

    /** @return list<string> */
    public function csvHeader(): array
    {
        return ['Секция', 'Метрика', 'Значение'];
    }

    /** @return list<list<string>> */
    public function buildCsvRows(?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveRange($from, $to);
        $rows = [];
        $rows[] = ['Период', 'С — По', $from . ' — ' . $to];
        $sum  = $this->summary($from, $to);
        $rows[] = ['Сводка', 'Игроков', (string) $sum['players']];
        $rows[] = ['Сводка', 'Всего золота', (string) $sum['total_gold']];
        $rows[] = ['Сводка', 'Среднее золото', (string) $sum['avg_gold']];
        $rows[] = ['Сводка', 'Крафт-записей (период)', (string) $sum['crafted_rows']];
        $rows[] = ['Сводка', 'Крафт-количество (период)', (string) $sum['crafted_qty']];
        $rows[] = ['Сводка', 'Ресурсов на руках', (string) $sum['resource_qty']];
        $rows[] = ['Сводка', 'Транзакций (период)', (string) $sum['transactions']];

        $gc = $this->goldConcentration(10);
        $topShare  = is_numeric($gc['top_share_pct'] ?? null) ? (string) $gc['top_share_pct'] : '0';
        $topnShare = is_numeric($gc['topn_share_pct'] ?? null) ? (string) $gc['topn_share_pct'] : '0';
        $rows[] = ['Золото', 'Доля топ-холдера, %', $topShare];
        $rows[] = ['Золото', 'Доля топ-10, %', $topnShare];
        $holders = is_array($gc['holders'] ?? null) ? $gc['holders'] : [];
        foreach ($holders as $h) {
            if (! is_array($h)) {
                continue;
            }
            $rows[] = ['Золото: холдер', $this->s($h['name'] ?? ''), (string) $this->n($h['gold'] ?? 0)];
        }
        foreach ($this->topCraftedItems(15, $from, $to) as $i) {
            $rows[] = ['Топ крафт', $i['name'], (string) $i['qty']];
        }
        foreach ($this->topResourcesHeld(15) as $i) {
            $rows[] = ['Топ ресурс', $i['name'], (string) $i['qty']];
        }
        foreach ($this->craftVolumeByMonth($from, $to) as $m) {
            $rows[] = ['Крафт/месяц', $m['month'], (string) $m['qty']];
        }
        return $rows;
    }

    // ── period helpers ────────────────────────────────────────────────────────

    /**
     * Нормализует/валидирует период. null или некорректный YYYY-MM-DD → дефолт
     * (последние DEFAULT_MONTHS месяцев). Возвращает [from, to] (обе YYYY-MM-DD).
     *
     * @return array{0:string,1:string}
     */
    private function resolveRange(?string $from, ?string $to): array
    {
        $to   = $this->validDate($to)   ?? date('Y-m-d');
        $from = $this->validDate($from) ?? date('Y-m-d', strtotime('-' . self::DEFAULT_MONTHS . ' months'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    private function validDate(?string $d): ?string
    {
        if ($d === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            return null;
        }
        return $d;
    }

    /** SQL-фрагмент «AND col BETWEEN from 00:00 AND to 23:59». from/to уже валидны. */
    private function between(string $col, string $from, string $to): string
    {
        return " AND {$col} >= '{$from} 00:00:00' AND {$col} <= '{$to} 23:59:59'";
    }

    // ── db helpers ─────────────────────────────────────────────────────────────

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
