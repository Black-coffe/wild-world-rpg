<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;

/**
 * W17 (ADR-071) — PvP-дуэли: stat-equalize честной арены.
 *
 * Pure GameSettings-reader + чистый пре-процесс структуры бойца ДО неизменного
 * `PvpRoundOrchestrator::simulateFight` (ADR-070 RNG-fence-safe план: simulateFight
 * не трогаем, дуэль — новый CALLER с equalized входом). Снаряжение НЕ нормализуется
 * (грузится по реальному id внутри боя) → решает билд игрока.
 */
final class DuelService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch дуэлей. Default OFF = dormant. */
    public function enabled(): bool
    {
        $v = $this->settings->get('pvp.duel.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function baselineLevel(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_level', 20);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 20;
    }

    public function baselineStat(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_stat', 50);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 50;
    }

    public function baselineHealth(): int
    {
        $v = $this->settings->get('pvp.duel.baseline_health', 1000);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 1000;
    }

    /**
     * Stat-equalize: клон бойца с нормализованными level/strength/agility/intellect/
     * health/max_health/tired к baseline. id/name/cell_number/faction/прочее — сохранены
     * (нужны для идентичности исхода + загрузки снаряжения по id внутри боя).
     * Снаряжение НЕ трогается — билд игрока решает.
     *
     * @param array<string,mixed> $char
     * @return array<string,mixed>
     */
    public function equalize(array $char): array
    {
        $level  = $this->baselineLevel();
        $stat   = $this->baselineStat();
        $health = $this->baselineHealth();

        $char['level']      = $level;
        $char['strength']   = $stat;
        $char['agility']    = $stat;
        $char['intellect']  = $stat;
        $char['health']     = $health;
        $char['max_health'] = $health;
        // tired ≥ 30 → без tired-штрафа EffectService (равный отыгрыш билда).
        $char['tired']      = 100;

        return $char;
    }

    /**
     * W18.5 (ADR-073) — детерминированный исход дуэли: убрать ничью тай-брейком ПОСЛЕ боя.
     *
     * Вызывается из DuelAction с результатом НЕИЗМЕНЁННОГО simulateFight + обоими (реальными)
     * бойцами. RNG-fence-safe: 0 нового mt_rand, движок не тронут — всё вычисляется из
     * `result['roundLogs']` + created_at/id. Ничья математически невозможна (см. ADR-073).
     *
     * Цепочка при 'exhausted': остаток HP (= меньше полученного урона) → ценность билда
     * (D_equip) → старшинство (раньше created_at; при равенстве/NULL — меньше id).
     * При 'normal' (килл) победитель уже есть → reason 'knockout'.
     *
     * @param array<string,mixed> $result выход simulateFight
     * @param array<string,mixed> $charA
     * @param array<string,mixed> $charB
     * @return array{winnerId:int, loserId:int, reason:string} reason: knockout|hp|build|seniority
     */
    public function resolveDuel(array $result, array $charA, array $charB): array
    {
        $aId = $this->intOf($charA['id'] ?? null);
        $bId = $this->intOf($charB['id'] ?? null);

        // Нокаут в бою — победитель определён движком.
        $type = is_string($result['type'] ?? null) ? $result['type'] : 'normal';
        if ($type !== 'exhausted') {
            $winner = is_array($result['winner'] ?? null) ? $result['winner'] : null;
            $wId    = $winner !== null ? $this->intOf($winner['id'] ?? null) : 0;
            if ($wId > 0) {
                $lId = $wId === $aId ? $bId : $aId;
                return ['winnerId' => $wId, 'loserId' => $lId, 'reason' => 'knockout'];
            }
            // winner не определился (edge) → падаем в тай-брейк ниже.
        }

        $aName = $this->nameOf($charA);
        $bName = $this->nameOf($charB);
        $logs  = is_array($result['roundLogs'] ?? null) ? $result['roundLogs'] : [];

        // 1) Остаток HP = меньше полученного урона (симметрия: оба стартуют с baseline HP).
        $dmgA = $this->damageTaken($logs, $aName);
        $dmgB = $this->damageTaken($logs, $bName);
        if ($dmgA < $dmgB) {
            return ['winnerId' => $aId, 'loserId' => $bId, 'reason' => 'hp'];
        }
        if ($dmgB < $dmgA) {
            return ['winnerId' => $bId, 'loserId' => $aId, 'reason' => 'hp'];
        }

        // 2) Ценность билда (D_equip как атакующего; снаряжение постоянно в бою).
        $buildA = $this->buildValue($logs, $aName);
        $buildB = $this->buildValue($logs, $bName);
        if ($buildA > $buildB) {
            return ['winnerId' => $aId, 'loserId' => $bId, 'reason' => 'build'];
        }
        if ($buildB > $buildA) {
            return ['winnerId' => $bId, 'loserId' => $aId, 'reason' => 'build'];
        }

        // 3) Старшинство: раньше created_at → победа; при равенстве/NULL — меньше id.
        if ($this->isSenior($charA, $aId, $charB, $bId)) {
            return ['winnerId' => $aId, 'loserId' => $bId, 'reason' => 'seniority'];
        }
        return ['winnerId' => $bId, 'loserId' => $aId, 'reason' => 'seniority'];
    }

    private function intOf(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    /** @param array<string,mixed> $char */
    private function nameOf(array $char): string
    {
        $n = $char['name'] ?? null;
        return is_string($n) && $n !== '' ? $n : '';
    }

    /**
     * Σ finalDamage round-логов, где боец был defender (= полученный им урон).
     *
     * @param array<int,mixed> $logs
     */
    private function damageTaken(array $logs, string $name): float
    {
        if ($name === '') {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($logs as $log) {
            if (! is_array($log) || ($log['defender'] ?? null) !== $name) {
                continue;
            }
            $fd = $log['finalDamage'] ?? null;
            $sum += is_numeric($fd) ? (float) $fd : 0.0;
        }
        return $sum;
    }

    /**
     * D_equip из первого round-лога, где боец атаковал (компонент урона от снаряжения).
     *
     * @param array<int,mixed> $logs
     */
    private function buildValue(array $logs, string $name): float
    {
        if ($name === '') {
            return 0.0;
        }
        foreach ($logs as $log) {
            if (! is_array($log) || ($log['attacker'] ?? null) !== $name) {
                continue;
            }
            $de = $log['D_equip'] ?? null;
            return is_numeric($de) ? (float) $de : 0.0;
        }
        return 0.0;
    }

    /**
     * A старше B: раньше created_at; при NULL/равенстве — меньше id (раньше зарегистрирован).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function isSenior(array $a, int $aId, array $b, int $bId): bool
    {
        $ta = $this->createdTs($a);
        $tb = $this->createdTs($b);
        if ($ta !== null && $tb !== null && $ta !== $tb) {
            return $ta < $tb;
        }
        if ($ta !== null && $tb === null) {
            return true;
        }
        if ($ta === null && $tb !== null) {
            return false;
        }
        // оба NULL или равны → меньше id = раньше зарегистрирован.
        return $aId <= $bId;
    }

    /** @param array<string,mixed> $char */
    private function createdTs(array $char): ?int
    {
        $c = $char['created_at'] ?? null;
        if (! is_string($c) || $c === '') {
            return null;
        }
        $ts = strtotime($c);
        return $ts === false ? null : $ts;
    }
}
