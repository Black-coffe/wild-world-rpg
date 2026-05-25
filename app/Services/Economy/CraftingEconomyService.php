<?php

declare(strict_types=1);

namespace App\Services\Economy;

use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * V21 (ADR-053) — агрегатор экономики для admin-дашборда `/admin/crafting-economy`.
 *
 * Read-only: только агрегирующие SELECT'ы из живых таблиц (characters / crafted_items_log /
 * crafted_items / character_resources / resources / transactions). Возвращает plain-массивы
 * для JSON-эндпоинта и CSV-экспорта. Ничего не пишет, баланс не меняет (правки — через
 * GameSettings, ADR-024). Реюз паттерна CraftTreeService (S30).
 */
final class CraftingEconomyService
{
    /**
     * Полный payload для дашборда.
     *
     * @return array<string,mixed>
     */
    public function dashboard(): array
    {
        return [
            'summary'            => $this->summary(),
            'gold_concentration' => $this->goldConcentration(10),
            'craft_volume'       => $this->craftVolumeByMonth(12),
            'top_crafted'        => $this->topCraftedItems(15),
            'top_resources'      => $this->topResourcesHeld(15),
            'transactions'       => $this->transactionsByMonth(12),
            'generated_at'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * KPI-снимок.
     *
     * @return array<string,int>
     */
    public function summary(): array
    {
        $chars = $this->row('SELECT COUNT(*) c, COALESCE(SUM(gold),0) g FROM characters');
        $log   = $this->row('SELECT COUNT(*) c, COALESCE(SUM(quantity),0) q FROM crafted_items_log');
        $res   = $this->row('SELECT COALESCE(SUM(quantity),0) q FROM character_resources');
        $tx    = $this->row('SELECT COUNT(*) c FROM transactions');

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
     * Концентрация золота: общий запас + топ-холдеры + доля топ-холдера (whale/инфляц-сигнал).
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
     * Объём крафта по месяцам (turnover).
     *
     * @return list<array{month:string,qty:int,count:int}>
     */
    public function craftVolumeByMonth(int $months = 12): array
    {
        $months = max(1, min(36, $months));
        $rows   = $this->rows(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COALESCE(SUM(quantity),0) qty, COUNT(*) cnt
             FROM crafted_items_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$months} MONTH)
             GROUP BY ym ORDER BY ym"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['month' => $this->s($r['ym'] ?? ''), 'qty' => $this->n($r['qty'] ?? 0), 'count' => $this->n($r['cnt'] ?? 0)];
        }
        return $out;
    }

    /**
     * Топ скрафченных предметов по суммарному количеству.
     *
     * @return list<array{name:string,type:string,qty:int}>
     */
    public function topCraftedItems(int $topN = 15): array
    {
        $topN = max(1, min(50, $topN));
        $rows = $this->rows(
            "SELECT ci.name_rus name, ci.type type, COALESCE(SUM(cil.quantity),0) qty
             FROM crafted_items_log cil JOIN crafted_items ci ON ci.id = cil.crafted_item_id
             GROUP BY cil.crafted_item_id ORDER BY qty DESC LIMIT {$topN}"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => $this->s($r['name'] ?? '—'), 'type' => $this->s($r['type'] ?? ''), 'qty' => $this->n($r['qty'] ?? 0)];
        }
        return $out;
    }

    /**
     * Топ удерживаемых ресурсов по суммарному количеству.
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
     * Транзакции по месяцам: объём buy/sell + средняя цена.
     *
     * @return list<array{month:string,buy:int,sell:int,avg_buy:int,avg_sell:int}>
     */
    public function transactionsByMonth(int $months = 12): array
    {
        $months = max(1, min(36, $months));
        $rows   = $this->rows(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') ym, type, COUNT(*) cnt, ROUND(AVG(price)) avg_price
             FROM transactions
             WHERE transaction_date >= DATE_SUB(NOW(), INTERVAL {$months} MONTH)
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
    public function buildCsvRows(): array
    {
        $rows = [];
        $sum  = $this->summary();
        $rows[] = ['Сводка', 'Игроков', (string) $sum['players']];
        $rows[] = ['Сводка', 'Всего золота', (string) $sum['total_gold']];
        $rows[] = ['Сводка', 'Среднее золото', (string) $sum['avg_gold']];
        $rows[] = ['Сводка', 'Крафт-записей', (string) $sum['crafted_rows']];
        $rows[] = ['Сводка', 'Крафт-количество', (string) $sum['crafted_qty']];
        $rows[] = ['Сводка', 'Ресурсов на руках', (string) $sum['resource_qty']];
        $rows[] = ['Сводка', 'Транзакций', (string) $sum['transactions']];

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
        foreach ($this->topCraftedItems(15) as $i) {
            $rows[] = ['Топ крафт', $i['name'], (string) $i['qty']];
        }
        foreach ($this->topResourcesHeld(15) as $i) {
            $rows[] = ['Топ ресурс', $i['name'], (string) $i['qty']];
        }
        foreach ($this->craftVolumeByMonth(12) as $m) {
            $rows[] = ['Крафт/месяц', $m['month'], (string) $m['qty']];
        }
        return $rows;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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
