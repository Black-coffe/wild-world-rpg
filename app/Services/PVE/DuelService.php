<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Services\GameSettings\GameSettingsService;
use Config\GameBalance;

/**
 * W17 (ADR-071) — PvP-дуэли: stat-equalize честной арены.
 *
 * Pure GameSettings-reader + чистый пре-процесс структуры бойца ДО неизменного
 * `PvpRoundOrchestrator::simulateFight` (ADR-070 RNG-fence-safe план: simulateFight
 * не трогаем, дуэль — новый CALLER с equalized входом). Снаряжение грузится по реальному id
 * внутри боя через {@see DuelEquipmentRepository} → билд игрока решает, но с весом.
 *
 * duel-baseline-weapon (поправка ADR-071): дуэль — спорт на одной площадке (оба бойца на клетке
 * вызывающего, дистанция не режет урон), базовое оружие для безоружных и слабых, вес перевеса своего
 * оружия, уворот на арене и HP дуэли — всё из `pvp.duel.*`. До фикса бой с арены делил урон на
 * дистанцию между реальными клетками (кулаки 2.0 / 457 клеток ≈ 0,004) и кончался стажем.
 */
final class DuelService
{
    public const DEFAULT_HEALTH        = 200;
    public const DEFAULT_WEAPON_DAMAGE = 10.0;
    public const DEFAULT_WEAPON_WEIGHT = 0.5;
    public const DEFAULT_DODGE_PERCENT = 40.0;

    /** Потолок уворота движка (`GameBalance::$maxDodgeChancePercent`). */
    private const MAX_DODGE_PERCENT = 75.0;

    /** `PvpFormulaService::getDodgeChance()`: уворот = ловкость × 0.25 (связь держит тест). */
    private const DODGE_PER_AGILITY = 0.25;

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
        $v = $this->settings->get('pvp.duel.baseline_health', self::DEFAULT_HEALTH);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : self::DEFAULT_HEALTH;
    }

    /** Урон базового оружия: им бьёт безоружный и тот, чьё оружие слабее (урон × редкость). */
    public function baselineWeaponDamage(): float
    {
        $v = $this->settings->get('pvp.duel.baseline_weapon_damage', self::DEFAULT_WEAPON_DAMAGE);
        return is_numeric($v) && (float) $v >= 1.0 ? (float) $v : self::DEFAULT_WEAPON_DAMAGE;
    }

    /** Доля перевеса своего оружия над базовым: 0 — у всех базовое, 1 — своё целиком. */
    public function weaponAdvantageWeight(): float
    {
        $v = $this->settings->get('pvp.duel.weapon_advantage_weight', self::DEFAULT_WEAPON_WEIGHT);
        return is_numeric($v) ? max(0.0, min(1.0, (float) $v)) : self::DEFAULT_WEAPON_WEIGHT;
    }

    /** Шанс уворота обоих бойцов в дуэли, %; равен у обоих, даёт исходу разброс. */
    public function dodgePercent(): float
    {
        $v = $this->settings->get('pvp.duel.dodge_percent', self::DEFAULT_DODGE_PERCENT);
        return is_numeric($v) ? max(0.0, min(self::MAX_DODGE_PERCENT, (float) $v)) : self::DEFAULT_DODGE_PERCENT;
    }

    /**
     * Анти-спам кулдаун между дуэлями одного вызывающего — тот же `pvp.attack_cooldown_sec`, что у
     * PvP-атаки (pvp-detection-clarity-26); `GameBalance` — страховочный дефолт при пустой `game_settings`.
     * w2-n7-combat-02: читается ядром арены, а не handler'ом.
     */
    public function cooldownSec(): int
    {
        $v = $this->settings->get('pvp.attack_cooldown_sec', config(GameBalance::class)->pvpAttackCooldownSec);

        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /**
     * Stat-equalize: клон бойца с нормализованными level/strength/intellect к baseline, agility —
     * под уворот дуэли (`dodgePercent()`), health/max_health/tired — к HP дуэли. id/name/faction/прочее
     * сохранены (нужны для идентичности исхода + загрузки снаряжения по id внутри боя).
     * `$cell` — клетка площадки: передан → бойцу ставится эта клетка (дуэль без дистанции).
     *
     * @param array<string,mixed> $char
     * @return array<string,mixed>
     */
    public function equalize(array $char, ?int $cell = null): array
    {
        $level  = $this->baselineLevel();
        $stat   = $this->baselineStat();
        $health = $this->baselineHealth();

        $char['level']      = $level;
        $char['strength']   = $stat;
        $char['agility']    = $this->dodgePercent() / self::DODGE_PER_AGILITY;
        $char['intellect']  = $stat;
        $char['health']     = $health;
        $char['max_health'] = $health;
        // tired ≥ 30 → без tired-штрафа EffectService (равный отыгрыш билда).
        $char['tired']      = 100;
        if ($cell !== null) {
            $char['cell_number'] = $cell;
        }

        return $char;
    }

    /**
     * Пара бойцов дуэли: оба уравнены и стоят на одной площадке — клетке вызывающего.
     *
     * @param array<string,mixed> $attacker
     * @param array<string,mixed> $defender
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    public function prepare(array $attacker, array $defender): array
    {
        $cell = is_numeric($attacker['cell_number'] ?? null) ? (int) $attacker['cell_number'] : 0;

        return [$this->equalize($attacker, $cell), $this->equalize($defender, $cell)];
    }

    /**
     * Бой уравненной пары на неизменном `simulateFight` (спорт, без защиты базы). Оружие — через
     * {@see DuelEquipmentRepository} поверх `$repo`: базовое для безоружных и слабых, перевес с весом.
     * Броня и клетки — из `$repo` как есть.
     *
     * @param array<string,mixed> $eqAttacker
     * @param array<string,mixed> $eqDefender
     * @param array<string,mixed>|\App\Entities\BiomeEntity $biome
     * @return array<string,mixed> выход simulateFight
     */
    public function simulate(array $eqAttacker, array $eqDefender, array|\App\Entities\BiomeEntity $biome, PvpEquipmentRepository $repo): array
    {
        $formulas = new PvpFormulaService();
        $duelRepo = new DuelEquipmentRepository($repo, $this->baselineWeaponDamage(), $this->weaponAdvantageWeight(), $formulas);

        return (new PvpRoundOrchestrator(new PvpDamageCalculator($formulas, $duelRepo), $formulas))
            ->simulateFight($eqAttacker, $eqDefender, $biome, null);
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
