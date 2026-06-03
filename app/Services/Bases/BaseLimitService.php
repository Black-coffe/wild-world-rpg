<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-095 Фаза 1a — лимит количества баз по уровню персонажа (admin-tunable).
 *
 * Идея «Строительство» (Asana): каждые N уровней = +1 доступная база, до жёсткого
 * предела. Анкеры из ТЗ: на ~L100 — 2 базы, на L999 — 19 баз. Формула:
 *
 *     maxBases = max(1, min(hardCap, floor(level / levelsPerBase)))
 *
 * → L1..(N-1) = 1 база (стартовая бесплатна), L100 = 2, L150 = 3, … L999 = 19.
 *
 * Заменяет хардкод «макс 2, 2-я с L100» в EntrenchAction. Чистый GameSettings-reader
 * (как FoodBuffService / ConsumableExpiryService), без обращений к БД-моделям.
 * Live-tunable `buildings.bases.*` (ADR-024): levels_per_base, hard_cap.
 *
 * NB: «активная база» (манипуляции только с одной локацией) и лимит построек/ячейку —
 * отдельная Фаза 1b (рефактор first-base-only → location-aware). hard_cap держим на
 * целевых 19, но мульти-бэйс станет полностью когерентным только после 1b.
 */
final class BaseLimitService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Сколько уровней персонажа открывают одну дополнительную базу. */
    public function levelsPerBase(): int
    {
        return max(1, $this->intSetting('buildings.bases.levels_per_base', 50));
    }

    /** Жёсткий потолок числа баз на персонажа (анкер ТЗ: 19 на L999). */
    public function hardCap(): int
    {
        return max(1, $this->intSetting('buildings.bases.hard_cap', 19));
    }

    /**
     * Максимум баз, доступных персонажу данного уровня.
     * max(1, min(hardCap, floor(level / levelsPerBase))) — стартовая база бесплатна.
     */
    public function maxBasesForLevel(int $level): int
    {
        $lvl  = max(0, $level);
        $byLvl = intdiv($lvl, $this->levelsPerBase());
        return max(1, min($this->hardCap(), $byLvl));
    }

    /** Может ли персонаж построить ещё одну базу (текущих баз < лимита уровня). */
    public function canBuildMore(int $level, int $currentCount): bool
    {
        return $currentCount < $this->maxBasesForLevel($level);
    }

    /**
     * Уровень, на котором откроется СЛЕДУЮЩАЯ база при текущем числе баз.
     * null — достигнут жёсткий потолок (больше баз не будет на любом уровне).
     */
    public function nextBaseLevel(int $currentCount): ?int
    {
        $next = $currentCount + 1;
        if ($next > $this->hardCap()) {
            return null; // глобальный максимум
        }
        if ($next <= 1) {
            return 1; // стартовая база доступна сразу
        }
        return $next * $this->levelsPerBase();
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
