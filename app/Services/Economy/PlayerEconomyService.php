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
        return $this->boolSetting('economy.player_report.enabled');
    }

    /** W25 (ADR-080) — killswitch блока сравнения «на фоне выживших». */
    public function comparisonEnabled(): bool
    {
        return $this->boolSetting('economy.comparison.enabled');
    }

    private function boolSetting(string $key): bool
    {
        try {
            $v = (new GameSettingsService())->get($key, false);
        } catch (\Throwable) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        return is_numeric($v) && (int) $v === 1;
    }

    /** W25 — минимум членов фракции для осмысленного сравнения (tunable). */
    private function factionMinMembers(): int
    {
        try {
            $v = (new GameSettingsService())->get('economy.comparison.faction_min_members', 5);
        } catch (\Throwable) {
            return 5;
        }
        return is_numeric($v) ? max(1, (int) $v) : 5;
    }

    /**
     * W25 (ADR-080) — сравнение чистой стоимости игрока с выжившими.
     *
     * Audit: фракцию выбрали лишь 23/365 чаров (малые выборки) → основное сравнение
     * server-wide перцентиль (осмысленно для всех); faction-строка — ТОЛЬКО если в
     * фракции ≥ factionMinMembers (иначе медиана = ты сам, бессмысленно). Анонимно.
     * Один батч-запрос net worth по всем чарам (~55мс, не hot-path).
     *
     * @return array{
     *   net_worth: int,
     *   server: array{count: int, rank: int, richer_than_pct: int, median: int},
     *   faction: array{name: string, count: int, rank: int, median: int}|null
     * }|null  null если персонаж не найден среди чаров
     */
    public function comparison(int $charId): ?array
    {
        $db = Database::connect();
        $sql = 'SELECT c.id AS id, COALESCE(cf.faction_id,0) AS fid, f.name AS fname, '
            . '(COALESCE(c.gold,0) '
            . '+ COALESCE((SELECT SUM(cr.quantity*r.sell_price) FROM character_resources cr JOIN resources r ON r.id=cr.id_resources WHERE cr.id_characters=c.id),0) '
            . '+ COALESCE((SELECT SUM(cil.quantity*ci.price) FROM crafted_items_log cil JOIN crafted_items ci ON ci.id=cil.crafted_item_id WHERE cil.character_id=c.id),0)) AS nw '
            . 'FROM characters c '
            . 'LEFT JOIN character_factions cf ON cf.character_id = c.id '
            . 'LEFT JOIN factions f ON f.id = cf.faction_id';
        $res  = $db->query($sql);
        $rows = $res instanceof \CodeIgniter\Database\ResultInterface ? $res->getResultArray() : [];

        $serverNw = [];        // все net worth
        $playerNw = null;
        $playerFid = 0;
        $playerFname = '';
        $factionNw = [];       // net worth членов фракции игрока (заполним после)
        $factionRows = [];     // fid => list<int>
        foreach ($rows as $row) {
            $id  = $this->toInt($row['id'] ?? 0);
            $nw  = $this->toInt($row['nw'] ?? 0);
            $fid = $this->toInt($row['fid'] ?? 0);
            $serverNw[] = $nw;
            if ($fid > 0) {
                $factionRows[$fid][] = $nw;
            }
            if ($id === $charId) {
                $playerNw    = $nw;
                $playerFid   = $fid;
                $playerFname = isset($row['fname']) && is_scalar($row['fname']) ? (string) $row['fname'] : '';
            }
        }

        if ($playerNw === null) {
            return null;
        }

        $factionNw = $playerFid > 0 ? ($factionRows[$playerFid] ?? []) : [];

        $faction = null;
        if ($playerFid > 0 && count($factionNw) >= $this->factionMinMembers()) {
            $faction = [
                'name'   => $playerFname !== '' ? $playerFname : 'Фракция',
                'count'  => count($factionNw),
                'rank'   => $this->rankOf($playerNw, $factionNw),
                'median' => $this->median($factionNw),
            ];
        }

        return [
            'net_worth' => $playerNw,
            'server'    => [
                'count'           => count($serverNw),
                'rank'            => $this->rankOf($playerNw, $serverNw),
                'richer_than_pct' => $this->richerThanPct($playerNw, $serverNw),
                'median'          => $this->median($serverNw),
            ],
            'faction'   => $faction,
        ];
    }

    /**
     * Позиция (1 = богатейший): кол-во строго богаче + 1.
     *
     * @param list<int> $all
     */
    private function rankOf(int $value, array $all): int
    {
        $greater = 0;
        foreach ($all as $v) {
            if ($v > $value) {
                $greater++;
            }
        }
        return $greater + 1;
    }

    /**
     * «Богаче Y%»: доля строго беднее.
     *
     * @param list<int> $all
     */
    private function richerThanPct(int $value, array $all): int
    {
        $total = count($all);
        if ($total <= 1) {
            return 0;
        }
        $less = 0;
        foreach ($all as $v) {
            if ($v < $value) {
                $less++;
            }
        }
        return (int) round(100 * $less / ($total - 1));
    }

    /** @param list<int> $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $values[$mid];
        }
        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
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
