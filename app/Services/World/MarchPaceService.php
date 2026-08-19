<?php

namespace App\Services\World;

/**
 * transport-01 (tracer) — единственный источник арифметики темпа Похода.
 * Чистый сервис: без БД, без Telegram. Раньше формула жила двумя независимыми
 * копиями — в `MarchAction::showRouteSetup()` (превью) и `MarchingTaskHandler`
 * (реальный ход). Числа в этой story не меняются — это только схлопывание.
 *
 * Профиль транспорта — контракт `docs/specs/transport-system/plan.md → ## Contracts`:
 * ['key'=>?string,'cells_per_tick'=>int,'tired_factor'=>float,'max_steps_per_order'=>int,
 *  'cargo_share'=>float,'wear_per_cell'=>int]. В этой story на всех call-site передают
 * нейтраль (нет транспорта): `cells_per_tick` = база, `tired_factor` = 1.0. Профиль с
 * реальным транспортом появится в transport-04.
 */
class MarchPaceService
{
    public const TERRAIN_EXPLORED   = 'explored';
    public const TERRAIN_UNEXPLORED = 'unexplored';
    public const TERRAIN_COLD       = 'cold';

    /**
     * Клеток за крон-тик: не меньше базы (world.march.cells_per_tick), не больше
     * hard_max 5 (concept-final.md §1).
     *
     * @param array<string,mixed> $profile
     */
    public function cellsPerTick(int $base, array $profile): int
    {
        $raw         = $profile['cells_per_tick'] ?? $base;
        $fromProfile = is_numeric($raw) ? (int) $raw : $base;

        return min(5, max($base, $fromProfile));
    }

    /** ETA марша в минутах: `$cells` клеток пачками по `$cellsPerTick` за тик, тик = `$minutesPerCell` мин. */
    public function etaMinutes(int $cells, int $cellsPerTick, int $minutesPerCell): int
    {
        $cellsPerTick = max(1, $cellsPerTick);
        $ticks        = (int) ceil(max(0, $cells) / $cellsPerTick);

        return $ticks * $minutesPerCell;
    }

    /**
     * Интервал «созревания» шага марша: `minutes_per_cell − 1` мин — намеренная
     * компенсация дрожания крона (см. историю в `MarchingTaskHandler::stepDueInterval()`).
     * Профиль сюда не попадает никогда — темп такта одинаков для всех.
     */
    public function stepDueInterval(int $minutesPerCell): int
    {
        return max(0, $minutesPerCell - 1);
    }

    /**
     * Расход 💤 за клетку: база × `tired_factor` профиля (нейтраль 1.0 — без изменений).
     *
     * @param array<string,mixed> $profile
     */
    public function tiredCostPerCell(float $base, array $profile): float
    {
        $raw    = $profile['tired_factor'] ?? 1.0;
        $factor = is_numeric($raw) ? (float) $raw : 1.0;

        return $base * $factor;
    }

    /**
     * Расход ❤️ за клетку: профиль его не трогает — всегда база.
     *
     * @param array<string,mixed> $profile
     */
    public function healthCostPerCell(float $base, array $profile): float
    {
        return $base;
    }
}
