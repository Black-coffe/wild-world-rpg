<?php

declare(strict_types=1);

namespace App\Services\Economy;

use App\Services\GameSettings\GameSettingsService;
use Config\Database;

/**
 * W24 (ADR-079) — «💰 Моя экономика»: персональный экономический срез игрока.
 *
 * Audit-first: per-character gold-ledger ПО ВРЕМЕНИ на проде НЕ существует
 * (action_log без сумм, transactions редкие). Поэтому отчёт — ЧЕСТНЫЙ SNAPSHOT
 * из существующих данных (работает для всех игроков сразу), НЕ time-series:
 *   - net worth = gold + стоимость ресурсов (×sell_price) + стоимость крафта (×price)
 *   - топ-холдинги по ценности
 *   - сводка торговли из transactions (если торговал; иначе null)
 *
 * «Личная инфляция / профит за месяц» (roadmap W24) отложены — требуют gold-ledger
 * (отдельная foundation-сессия + месяцы накопления). Killswitch economy.player_report.enabled.
 */
final class PlayerEconomyService
{
    public function enabled(): bool
    {
        try {
            $v = (new GameSettingsService())->get('economy.player_report.enabled', false);
        } catch (\Throwable) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        return is_numeric($v) && (int) $v === 1;
    }

    /**
     * Персональный экономический срез.
     *
     * @return array{
     *   gold: int,
     *   resources_value: int,
     *   crafted_value: int,
     *   net_worth: int,
     *   top_resources: list<array{name: string, qty: int, value: int}>,
     *   top_crafted: list<array{name: string, qty: int, value: int}>,
     *   trade: array{bought: int, sold: int, profit: int, count: int}|null
     * }
     */
    public function report(int $charId): array
    {
        $db = Database::connect();

        // Gold (может быть NULL → 0).
        $q       = $db->table('characters')->select('COALESCE(gold,0) AS gold')->where('id', $charId)->get();
        $goldRow = $q === false ? null : $q->getRowArray();
        $gold    = $this->toInt(is_array($goldRow) ? ($goldRow['gold'] ?? 0) : 0);

        // Стоимость ресурсов = Σ quantity × sell_price.
        $q      = $db->table('character_resources cr')
            ->select('COALESCE(SUM(cr.quantity * r.sell_price),0) AS v')
            ->join('resources r', 'r.id = cr.id_resources')
            ->where('cr.id_characters', $charId)
            ->get();
        $resRow = $q === false ? null : $q->getRowArray();
        $resourcesValue = $this->toInt(is_array($resRow) ? ($resRow['v'] ?? 0) : 0);

        // Стоимость крафта = Σ quantity × price.
        $q        = $db->table('crafted_items_log cil')
            ->select('COALESCE(SUM(cil.quantity * ci.price),0) AS v')
            ->join('crafted_items ci', 'ci.id = cil.crafted_item_id')
            ->where('cil.character_id', $charId)
            ->get();
        $craftRow = $q === false ? null : $q->getRowArray();
        $craftedValue = $this->toInt(is_array($craftRow) ? ($craftRow['v'] ?? 0) : 0);

        return [
            'gold'            => $gold,
            'resources_value' => $resourcesValue,
            'crafted_value'   => $craftedValue,
            'net_worth'       => $gold + $resourcesValue + $craftedValue,
            'top_resources'   => $this->topResources($charId),
            'top_crafted'     => $this->topCrafted($charId),
            'trade'           => $this->tradeSummary($charId),
        ];
    }

    /**
     * @return list<array{name: string, qty: int, value: int}>
     */
    private function topResources(int $charId): array
    {
        $q = Database::connect()->table('character_resources cr')
            ->select('r.name AS name, cr.quantity AS qty, (cr.quantity * r.sell_price) AS value')
            ->join('resources r', 'r.id = cr.id_resources')
            ->where('cr.id_characters', $charId)
            ->where('cr.quantity >', 0)
            ->orderBy('value', 'DESC')
            ->limit(5)
            ->get();

        return $this->mapHoldings($q === false ? [] : $q->getResultArray());
    }

    /**
     * @return list<array{name: string, qty: int, value: int}>
     */
    private function topCrafted(int $charId): array
    {
        $q = Database::connect()->table('crafted_items_log cil')
            ->select('ci.name_rus AS name, SUM(cil.quantity) AS qty, SUM(cil.quantity * ci.price) AS value')
            ->join('crafted_items ci', 'ci.id = cil.crafted_item_id')
            ->where('cil.character_id', $charId)
            ->where('cil.quantity >', 0)
            ->groupBy('ci.id')
            ->orderBy('value', 'DESC')
            ->limit(3)
            ->get();

        return $this->mapHoldings($q === false ? [] : $q->getResultArray());
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return list<array{name: string, qty: int, value: int}>
     */
    private function mapHoldings(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $name = isset($r['name']) && is_scalar($r['name']) ? (string) $r['name'] : '?';
            $out[] = [
                'name'  => $name,
                'qty'   => $this->toInt($r['qty'] ?? 0),
                'value' => $this->toInt($r['value'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Сводка торговли из transactions (price = итог строки). null если не торговал.
     *
     * @return array{bought: int, sold: int, profit: int, count: int}|null
     */
    private function tradeSummary(int $charId): ?array
    {
        $q = Database::connect()->table('transactions')
            ->select("COALESCE(SUM(CASE WHEN type='buy' THEN price ELSE 0 END),0) AS bought, COALESCE(SUM(CASE WHEN type='sell' THEN price ELSE 0 END),0) AS sold, COUNT(*) AS cnt")
            ->where('character_id', $charId)
            ->get();
        $row = $q === false ? null : $q->getRowArray();
        if (! is_array($row)) {
            return null;
        }

        $count = $this->toInt($row['cnt'] ?? 0);
        if ($count === 0) {
            return null;
        }
        $bought = $this->toInt($row['bought'] ?? 0);
        $sold   = $this->toInt($row['sold'] ?? 0);
        return [
            'bought' => $bought,
            'sold'   => $sold,
            'profit' => $sold - $bought,
            'count'  => $count,
        ];
    }

    private function toInt(mixed $v): int
    {
        return is_numeric($v) ? (int) round((float) $v) : 0;
    }
}
