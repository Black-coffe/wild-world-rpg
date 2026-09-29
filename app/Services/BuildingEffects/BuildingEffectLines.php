<?php

declare(strict_types=1);

namespace App\Services\BuildingEffects;

use App\Services\PVE\DefenseStructureService;
use Config\GameBalance;

/**
 * w2-n4-tails-02 (ADR-190) — строка эффекта постройки на уровне, без транспорта. Раньше жила внутри
 * `Camp\BaseDevelopmentAction` (E18, ADR-118); теперь её берут три экрана: «🏗 Развитие базы»
 * ({@see developmentLine()}, текст прежний до символа), запрос апгрейда бота и веб-карточка апгрейда
 * ({@see effectAt()} для «сейчас → после» через `BuildingUpgradeService::preview()`).
 *
 * Числа — из авторитетных источников, формулы не дублируются: множители — {@see BuildingEffectsService::effectAtLevel()},
 * вода/сила — `GameBalance`, оборона — {@see DefenseStructureService} (`scaledInt`/cap), радиус роботов — уровень×100.
 * Только активные эффекты: ёмкость Склада (dormant weight-cap) не показывается. Строки markdown-safe (без `*`/`_`).
 */
final class BuildingEffectLines
{
    /** Здания с уровневым множителем эффекта: name_en → [param, kind(reduce|increase), label, icon]. */
    public const EFFECTS = [
        'Workshop'            => ['craft_time_multiplier',  'reduce',   'время крафта',        '🔧'],
        'BlastFurnace'        => ['craft_yield_multiplier', 'increase', 'выход плавки',        '🔥'],
        'Laboratory'          => ['craft_time_multiplier',  'reduce',   'время медицины',      '🥼'],
        'RoboticsWorkshop'    => ['craft_time_multiplier',  'reduce',   'время роботов',       '🤖'],
        'Greenhouse'          => ['harvest_yield_multiplier','increase','урожай',              '🌱'],
        'SolarStation'        => ['craft_time_multiplier',  'reduce',   'время электроники',   '☀️'],
        'TeleportationCenter' => ['teleport_cost_multiplier','reduce',  'цена телепорта',      '🌀'],
    ];

    /** Иконки прочих зданий (эффект-строки E18 Ф2 — не-множительные). */
    public const ICONS = [
        'HandPump' => '🚰', 'Gym' => '🥊', 'Warehouse' => '🏚️', 'Arsenal' => '⚔️',
        'CommunicationTower' => '📢', 'WoodenWall' => '🪵', 'BarbedFence' => '🌵', 'WatchTower' => '🗼',
    ];

    private const MAX_LEVEL = 10;

    private BuildingEffectsService $effects;
    private DefenseStructureService $defense;
    private GameBalance $gb;

    public function __construct(?BuildingEffectsService $effects = null, ?DefenseStructureService $defense = null, ?GameBalance $gb = null)
    {
        $this->effects = $effects ?? new BuildingEffectsService();
        $this->defense = $defense ?? new DefenseStructureService();
        $this->gb      = $gb ?? config(GameBalance::class);
    }

    public static function icon(string $nameEn): string
    {
        return self::EFFECTS[$nameEn][3] ?? (self::ICONS[$nameEn] ?? '🏗');
    }

    /**
     * Эффект постройки на уровне `$level` одной фразой («−12% время крафта», «≈3 воды/мин (зависит от биома)»);
     * `null` — у постройки нет показываемого эффекта.
     */
    public function effectAt(string $nameEn, int $level): ?string
    {
        return $this->parts($nameEn, $level, false)['cur'] ?? null;
    }

    /**
     * Строка «Развития базы»: эффект на уровне и, если растёт, «  →  ур.N+1: …» (прежний формат E18).
     * `null` — строки нет.
     */
    public function developmentLine(string $nameEn, int $level): ?string
    {
        $p = $this->parts($nameEn, $level, true);
        if ($p === null) {
            return null;
        }

        return $p['next'] === null ? $p['cur'] : "{$p['cur']}  →  ур.{$p['lvl']}+1: {$p['next']}";
    }

    /**
     * Текущий эффект и (при `$withNext`) короткий фрагмент следующего уровня — формы как были в E18.
     *
     * @return array{cur: string, next: string|null, lvl: int}|null
     */
    private function parts(string $nameEn, int $level, bool $withNext): ?array
    {
        if (isset(self::EFFECTS[$nameEn])) {
            [$param, $kind, $label] = self::EFFECTS[$nameEn];
            $key  = strtolower($nameEn);
            $cur  = self::fmtEffect($this->effects->effectAtLevel($key, $level, $param), $kind, $label);
            $next = $withNext && $level < self::MAX_LEVEL
                ? self::fmtEffect($this->effects->effectAtLevel($key, $level + 1, $param), $kind, $label)
                : null;

            return ['cur' => $cur, 'next' => $next, 'lvl' => $level];
        }

        $lvl  = max(1, min(self::MAX_LEVEL, $level));
        $next = $withNext && $lvl < self::MAX_LEVEL ? $lvl + 1 : null;

        switch ($nameEn) {
            case 'HandPump':
                $cur = $this->gb->handPumpLevels[$lvl] ?? 1;

                return ['cur' => "≈{$cur} воды/мин (зависит от биома)", 'next' => $next !== null ? '≈' . ($this->gb->handPumpLevels[$next] ?? $cur) : null, 'lvl' => $lvl];

            case 'Gym':
                $cur = $this->gb->gymStrengthByLevel[$lvl] ?? 0.01;

                return ['cur' => "+{$cur} силы / 30 мин", 'next' => $next !== null ? '+' . ($this->gb->gymStrengthByLevel[$next] ?? $cur) : null, 'lvl' => $lvl];

            case 'CommunicationTower':
                return ['cur' => 'радиус роботов: ' . ($lvl * 100) . ' клеток', 'next' => $next !== null ? ($next * 100) . ' клеток' : null, 'lvl' => $lvl];

            case 'WoodenWall':
                $cap = $this->defense->totalReductionCapPercent();
                $cur = min($this->defense->scaledInt('defense.wall.damage_reduction_percent', 15, $lvl), $cap);

                return [
                    'cur'  => "−{$cur}% урона по базе при рейде (макс {$cap}%)",
                    'next' => $next !== null ? '−' . min($this->defense->scaledInt('defense.wall.damage_reduction_percent', 15, $next), $cap) . '%' : null,
                    'lvl'  => $lvl,
                ];

            case 'BarbedFence':
                $cur = $this->defense->scaledInt('defense.fence.attacker_damage_per_round', 3, $lvl);

                return [
                    'cur'  => "+{$cur} контрурона атакующему/раунд",
                    'next' => $next !== null ? '+' . $this->defense->scaledInt('defense.fence.attacker_damage_per_round', 3, $next) : null,
                    'lvl'  => $lvl,
                ];

            case 'WatchTower':
                $cur = $this->defense->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $lvl);

                return [
                    'cur'  => "+{$cur}% инициативы в обороне + алерт о подходе врага",
                    'next' => $next !== null ? '+' . $this->defense->scaledInt('defense.tower.defender_initiative_bonus_percent', 8, $next) . '%' : null,
                    'lvl'  => $lvl,
                ];

            case 'Warehouse':
                return ['cur' => 'закрытый рынок (покупка крафта) + бонус к продаже (флэт)', 'next' => null, 'lvl' => $lvl];

            case 'Arsenal':
                return ['cur' => 'хранение и экипировка оружия и брони (флэт)', 'next' => null, 'lvl' => $lvl];

            default:
                return null;
        }
    }

    /** Множитель → читаемый эффект: reduce (m<1) «−N% label», increase (m>1) «+N% label». */
    private static function fmtEffect(float $mult, string $kind, string $label): string
    {
        if ($kind === 'reduce') {
            $pct = (int) round((1.0 - $mult) * 100);

            return $pct > 0 ? "−{$pct}% {$label}" : 'базовый эффект';
        }
        $pct = (int) round(($mult - 1.0) * 100);

        return $pct > 0 ? "+{$pct}% {$label}" : 'базовый эффект';
    }
}
