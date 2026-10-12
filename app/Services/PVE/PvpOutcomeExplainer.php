<?php

declare(strict_types=1);

namespace App\Services\PVE;

use Config\GameBalance;

/**
 * oct-pvp-clarity (ADR-190) — нейтральный разбор «почему так вышло» для PvP-боя.
 *
 * Читает только `log_data` боя (факты, а не пересимуляцию) и отдаёт готовые строки без разметки.
 * Бот и `/play` их только выводят (через `BattleJournalService::card()` → `battle.why`); имена
 * игроков в строках сырые — экранирует рендер.
 *
 * Факторы:
 *  - стена базы: `defense.wall_reduction_percent` — на сколько срезан каждый удар по хозяину базы;
 *  - ограда: `defense.fence_damage_total` — сколько HP она по факту сняла с нападавшего;
 *  - инициатива хозяина базы: `defense.tower_initiative_percent` (вышка и боевой дрон);
 *  - удачные удары младшего по уровню: раунды с `luckyStrikeApplied`;
 *  - смягчённые удары насмерть: `oneShotRoll < oneShotChance`, но `finalDamage < defenderHealthBefore` —
 *    удар выставил урон равным здоровью цели, а стена базы срезала его (баланс так и задуман);
 *  - потолки: Сила выше точки насыщения бонуса и разница уровней больше `levelDiffCap` урона не добавляют.
 *
 * Посторонний зритель (публичная `/battles/view` без входа участника) получает `$forParticipant = false`:
 * вместо стены, ограды и инициативы — одна строка «у хозяина базы была оборона», без чисел. Насколько
 * укреплена чужая база, видят только участники боя (поправка владельца 2026-10-12, классы
 * `record-exposed-beyond-participants` и `entry-off-audience-path`).
 *
 * Старый бой без `defense` — без строк про стену, ограду и инициативу. Дуэль и PvE — пустой список
 * (в дуэли оборона не участвует, а Сила уравнена). Числа потолков — из `Config\GameBalance` и
 * `PvpFormulaService`, своих в коде нет.
 */
final class PvpOutcomeExplainer
{
    private GameBalance $balance;
    private PvpFormulaService $formulas;

    public function __construct(?GameBalance $balance = null, ?PvpFormulaService $formulas = null)
    {
        $cfg            = config('GameBalance');
        $this->balance  = $balance ?? ($cfg instanceof GameBalance ? $cfg : new GameBalance());
        $this->formulas = $formulas ?? new PvpFormulaService($this->balance);
    }

    /**
     * @param array<string, mixed> $log `log_data` боя (уже декодированный)
     * @return list<string>
     */
    public function explain(string $battleType, array $log, bool $forParticipant = true): array
    {
        if ($battleType !== BattleJournalService::TYPE_PVP || ($log['duel'] ?? false) === true) {
            return [];
        }
        $chars = is_array($log['characters'] ?? null) ? $log['characters'] : [];
        $att   = is_array($chars['attacker'] ?? null) ? $chars['attacker'] : null;
        $def   = is_array($chars['defender'] ?? null) ? $chars['defender'] : null;
        if ($att === null || $def === null) {
            return [];
        }
        $rounds = is_array($log['rounds'] ?? null) ? array_values(array_filter($log['rounds'], 'is_array')) : [];

        $lines = [];
        foreach ($this->defenseLines($log['defense'] ?? null, $att, $def, $forParticipant) as $line) {
            $lines[] = $line;
        }

        $lucky = $this->luckyCounts($rounds);
        foreach ($lucky as $name => $count) {
            $lines[] = sprintf(
                '🍀 Удачный удар младшего по уровню у %s: %d %s, урон ×%s.',
                $name,
                $count,
                self::times($count),
                self::fmt($this->balance->luckyStrikeDamageMult)
            );
        }

        $softened = $this->softenedOneShots($rounds);
        if ($softened > 0) {
            $lines[] = sprintf(
                '💀 Удар насмерть смягчён стеной базы: %d %s, цель выжила.',
                $softened,
                self::times($softened)
            );
        }

        $cap = $this->capsLine($att, $def);
        if ($cap !== null) {
            $lines[] = $cap;
        }

        return $lines;
    }

    /**
     * @param array<mixed> $att
     * @param array<mixed> $def
     * @return list<string>
     */
    private function defenseLines(mixed $raw, array $att, array $def, bool $forParticipant): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $ownerId = is_numeric($raw['owner_id'] ?? null) ? (int) $raw['owner_id'] : 0;
        $attId   = is_numeric($att['id'] ?? null) ? (int) $att['id'] : 0;
        // Хозяин базы обычно защитник; при контратаке («⚔️ Ударить первым») он в слоте атакующего.
        [$owner, $other] = ($ownerId > 0 && $ownerId === $attId) ? [$att, $def] : [$def, $att];
        $ownerName = self::name($owner);
        $otherName = self::name($other);

        $wall  = is_numeric($raw['wall_reduction_percent'] ?? null) ? (float) $raw['wall_reduction_percent'] : 0.0;
        $fence = is_numeric($raw['fence_damage_total'] ?? null) ? (float) $raw['fence_damage_total'] : 0.0;
        $init  = is_numeric($raw['tower_initiative_percent'] ?? null) ? (float) $raw['tower_initiative_percent'] : 0.0;

        if (! $forParticipant) {
            return ($wall > 0.0 || $fence > 0.0 || $init > 0.0)
                ? [sprintf('🛡 Бой шёл у базы %s, и её оборона работала на хозяина. Подробности видят участники боя в разборе.', $ownerName)]
                : [];
        }

        $out = [];
        if ($wall > 0.0) {
            $out[] = sprintf('🧱 Стена базы срезала каждый удар по хозяину базы (%s) на %s%%.', $ownerName, self::fmt($wall));
        }
        if ($fence > 0.0) {
            $out[] = sprintf('🌵 Колючая ограда сняла с нападавшего (%s) %s HP.', $otherName, self::fmt($fence));
        }
        if ($init > 0.0) {
            $source = ($raw['drone'] ?? false) === true ? 'вышка и боевой дрон' : 'вышка';
            $out[] = sprintf('🗼 Хозяину базы +%s%% к инициативе (%s): она решает, кто бьёт первым.', self::fmt($init), $source);
        }

        return $out;
    }

    /**
     * @param list<array<mixed>> $rounds
     * @return array<string, int>
     */
    private function luckyCounts(array $rounds): array
    {
        $out = [];
        foreach ($rounds as $r) {
            if (($r['luckyStrikeApplied'] ?? false) !== true) {
                continue;
            }
            $name       = is_scalar($r['attacker'] ?? null) && (string) $r['attacker'] !== '' ? (string) $r['attacker'] : '?';
            $out[$name] = ($out[$name] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @param list<array<mixed>> $rounds
     */
    private function softenedOneShots(array $rounds): int
    {
        $n = 0;
        foreach ($rounds as $r) {
            $roll   = $r['oneShotRoll'] ?? null;
            $chance = $r['oneShotChance'] ?? null;
            $dmg    = $r['finalDamage'] ?? null;
            $hp     = $r['defenderHealthBefore'] ?? null;
            if (! is_numeric($roll) || ! is_numeric($chance) || ! is_numeric($dmg) || ! is_numeric($hp)) {
                continue;
            }
            if ((float) $roll < (float) $chance && (float) $dmg < (float) $hp) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<mixed> $att
     * @param array<mixed> $def
     */
    private function capsLine(array $att, array $def): ?string
    {
        $maxStatsBonus = $this->formulas->computeStatsBonus(['strength' => PHP_INT_MAX]);
        $factor        = $this->balance->statsBonusFactor;
        $strengthCap   = $factor > 0.0 ? $maxStatsBonus / $factor : 0.0;
        $levelCap      = $this->balance->levelDiffCap;

        $strengthOver = false;
        foreach ([$att, $def] as $p) {
            if (is_numeric($p['strength'] ?? null) && (float) $p['strength'] > $strengthCap) {
                $strengthOver = true;
            }
        }
        $levelOver = is_numeric($att['level'] ?? null) && is_numeric($def['level'] ?? null)
            && abs((int) $att['level'] - (int) $def['level']) > $levelCap;

        $parts = [];
        if ($strengthOver && $strengthCap > 0.0) {
            $parts[] = sprintf('Сила выше %s даёт не больше +%s%% урона', self::fmt($strengthCap), self::fmt($maxStatsBonus * 100));
        }
        if ($levelOver) {
            $parts[] = sprintf(
                'разница больше %d уровней — не больше +%s%%',
                $levelCap,
                self::fmt($levelCap * $this->balance->levelDiffBonusPerLvl * 100)
            );
        }
        if ($parts === []) {
            return null;
        }

        return '⚖️ Потолки: ' . implode(', ', $parts) . '. Сверх этого урон не растёт.';
    }

    /** @param array<mixed> $p */
    private static function name(array $p): string
    {
        return is_scalar($p['name'] ?? null) && trim((string) $p['name']) !== '' ? (string) $p['name'] : '?';
    }

    private static function times(int $n): string
    {
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'раз';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'раза';
        }

        return 'раз';
    }

    /** Число без лишних нулей: 40 → «40», 13.5 → «13.5», 1.5 → «1.5». */
    private static function fmt(float $v): string
    {
        $r = round($v, 1);

        return $r === floor($r) ? (string) (int) $r : rtrim(rtrim(number_format($r, 1, '.', ''), '0'), '.');
    }
}
