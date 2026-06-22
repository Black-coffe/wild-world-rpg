<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\BossEngagementModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\Database;

/**
 * WB8 (ADR-137 «Узлы») — кооператив по вкладу («Облава»): ledger урона + дележ лута.
 *
 * Узел держит ОБЩИЙ персистентный HP (`boss_points.current_health`): игрок A ослабил и отступил,
 * игрок B добил. Каждый чанк урона по узлу пишется в `boss_engagements` атомарным апсёртом
 * (INSERT … ON DUPLICATE KEY UPDATE — без TOCTOU при двойных тапах). При килле лут (ЗОЛОТО, v1)
 * делится между всеми, кто внёс ≥ `combat.nodes.loot_min_contribution_pct` урона (от max_health),
 * ПРОПОРЦИОНАЛЬНО вкладу — НЕ last-hit ninja, НЕ AFK-пассажиру.
 *
 * Детерминированно (золото — чистая арифметика, без mt_rand → fixture-fence не затрагивается).
 * Soulbound-трофей (редкий дроп оружия/брони) — отдельный слой, выдаётся WB9 (взвешенная лотерея
 * среди тех же eligible-вкладчиков; сид-точка помечена ниже). «Клан/рейд» НЕ употреблять — только
 * «Облава».
 */
class BossLootService
{
    use GameSettingsReaderTrait;

    private BossEngagementModel $engagements;

    /** @var \CodeIgniter\Database\BaseConnection<object, object> */
    private $db;

    public function __construct()
    {
        $this->engagements = new BossEngagementModel();
        $this->db          = Database::connect();
    }

    /**
     * Записать вклад игрока в текущую жизнь узла (атомарный инкремент урона).
     *
     * Апсёрт по UNIQUE (boss_point_id, boss_level, character_id): первый удар = INSERT (first_hit_at),
     * последующие = damage_dealt += delta, rounds_participated += 1, last_hit_at = now. Эскалация
     * уровня (респавн) даёт другой boss_level → новые строки, старые не коллизят.
     */
    public function recordContribution(int $pointId, int $bossLevel, int $charId, int $damage): void
    {
        if ($pointId <= 0 || $charId <= 0 || $bossLevel <= 0 || $damage <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO boss_engagements '
            . '(boss_point_id, boss_level, character_id, damage_dealt, rounds_participated, first_hit_at, last_hit_at) '
            . 'VALUES (?, ?, ?, ?, 1, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'damage_dealt = damage_dealt + VALUES(damage_dealt), '
            . 'rounds_participated = rounds_participated + 1, '
            . 'last_hit_at = VALUES(last_hit_at)',
            [$pointId, $bossLevel, $charId, $damage, $now, $now]
        );
    }

    /**
     * Разделить лут узла между вкладчиками при килле и потребить ledger этой жизни.
     *
     * Eligible = вкладчики с долей урона ≥ loot_min_contribution_pct от max_health узла (отсекает
     * AFK; killer НЕ привилегирован — чистый анти-ninja). Золото (пул = goldPool(level)) делится
     * пропорционально вкладу среди eligible (каждый ≥1). Строки (pointId, bossLevel) удаляются.
     *
     * @param array<array-key, mixed> $point строка boss_points (нужны id, current_level, max_health)
     * @return array{participants:int,totalDamage:int,goldPool:int,goldByChar:array<int,int>,killerGold:int,eligible:array<int,int>}
     */
    public function distributeLoot(array $point, int $bossLevel, int $killerId): array
    {
        $pointId   = $this->ival($point['id'] ?? null);
        $maxHealth = max(1, $this->ival($point['max_health'] ?? null));
        $empty     = ['participants' => 0, 'totalDamage' => 0, 'goldPool' => 0, 'goldByChar' => [], 'killerGold' => 0, 'eligible' => []];
        if ($pointId <= 0 || $bossLevel <= 0) {
            return $empty;
        }

        // Вклады этой жизни узла.
        $rows = $this->engagements
            ->where('boss_point_id', $pointId)
            ->where('boss_level', $bossLevel)
            ->where('damage_dealt >', 0)
            ->findAll();
        if ($rows === []) {
            return $empty;
        }

        /** @var array<int,int> $byChar charId => damage */
        $byChar = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $cid = $this->ival($r['character_id'] ?? null);
            if ($cid > 0) {
                $byChar[$cid] = ($byChar[$cid] ?? 0) + $this->ival($r['damage_dealt'] ?? null);
            }
        }

        $minPct    = max(0.0, $this->gsFloat('combat.nodes.loot_min_contribution_pct', 0.05));
        $minDamage = (int) ceil($maxHealth * $minPct);

        /** @var array<int,int> $eligible charId => damage */
        $eligible = array_filter($byChar, static fn (int $dmg): bool => $dmg >= $minDamage);
        // Защита: если порог отсёк всех (редко — реген размазал урон), берём топ-вкладчика.
        if ($eligible === [] && $byChar !== []) {
            $topChar            = (int) array_search(max($byChar), $byChar, true);
            $eligible[$topChar] = $byChar[$topChar];
        }

        $totalEligible = array_sum($eligible);
        $pool          = $this->goldPool($bossLevel);

        /** @var array<int,int> $goldByChar */
        $goldByChar = [];
        if ($totalEligible > 0 && $pool > 0) {
            foreach ($eligible as $cid => $dmg) {
                $gold = max(1, (int) floor($pool * ($dmg / $totalEligible)));
                $this->db->table('characters')->where('id', $cid)->set('gold', "gold + {$gold}", false)->update();
                $goldByChar[$cid] = $gold;
            }
        }

        // ── WB9 seam: взвешенная по вкладу лотерея soulbound-трофея среди $eligible ──
        //    Должна выполняться ЗДЕСЬ (до purge) — eligible/totalEligible уже посчитаны.

        // Потребить ledger этой жизни узла.
        $this->engagements
            ->where('boss_point_id', $pointId)
            ->where('boss_level', $bossLevel)
            ->delete();

        return [
            'participants' => count($byChar),
            'totalDamage'  => array_sum($byChar),
            'goldPool'     => $pool,
            'goldByChar'   => $goldByChar,
            'killerGold'   => $goldByChar[$killerId] ?? 0,
            'eligible'     => $eligible,
        ];
    }

    /**
     * Пул золота узла уровня $level: clamp(level × gold_per_level, gold_floor, gold_hard_cap).
     */
    public function goldPool(int $level): int
    {
        $level   = max(1, $level);
        $perLvl  = max(0, $this->gsInt('combat.nodes.gold_per_level', 50));
        $floor   = max(0, $this->gsInt('combat.nodes.gold_floor', 100));
        $hardCap = max($floor, $this->gsInt('combat.nodes.gold_hard_cap', 50000));

        $pool = $level * $perLvl;
        if ($pool < $floor) {
            $pool = $floor;
        }
        if ($pool > $hardCap) {
            $pool = $hardCap;
        }

        return $pool;
    }

    /**
     * GC: удалить строки ledger по НЕДОБИТЫМ узлам, не тронутые дольше engagement_ttl_min.
     * Killed-узлы потребляются в distributeLoot; этот метод чистит «висящий» вклад. Возвращает
     * число удалённых (для лога/тестов). Вызывается из NodeEncounterGcHandler.
     */
    public function purgeStale(): int
    {
        $ttl    = max(1, $this->gsInt('combat.nodes.engagement_ttl_min', 720));
        $cutoff = date('Y-m-d H:i:s', time() - $ttl * 60);

        $this->db->table('boss_engagements')->where('last_hit_at <', $cutoff)->delete();

        return $this->db->affectedRows();
    }

    private function ival(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
