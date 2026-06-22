<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsReaderTrait;

/**
 * WB4 (ADR-137 «Узлы») — балансная кривая узлов: гео-старт-уровень и HP по уровню.
 *
 * Чистые формулы (детерминированные, без RNG/БД-состояния), параметры — admin-tunable
 * (ADR-024) с дефолтами из ROADMAP-WORLD-BOSSES §2. Используется seed'ом 24 точек (WB4)
 * и NodeRespawnHandler'ом эскалации/респавна (WB5) — единый источник правды по числам.
 *
 * Карта 1000×1000; **север = низкий Y** (пояс опасности), **юг = высокий Y** (новички).
 */
class NodeLevelCurve
{
    use GameSettingsReaderTrait;

    public const MAP_MAX_Y = 999;

    /**
     * Гео-старт-уровень узла = f(широта). Юг (Y=999) → south_base (L1, «валит кто угодно»),
     * север (Y=0) → north_base, линейно между. Клампится в [1, max_level].
     */
    public function baseLevelForY(int $y): int
    {
        $southBase = $this->gsInt('world.nodes.south_base_level', 1);
        $northBase = $this->gsInt('world.nodes.north_base_level', 50);
        $maxLevel  = $this->gsInt('world.nodes.max_level', 180);

        $y    = max(0, min(self::MAP_MAX_Y, $y));
        $frac = (self::MAP_MAX_Y - $y) / self::MAP_MAX_Y; // 0 на юге → 1 на севере
        $lvl  = (int) round($southBase + $frac * ($northBase - $southBase));

        return max(1, min($maxLevel, $lvl));
    }

    /**
     * HP узла по уровню (кусочно-линейная, монотонная, НЕ экспонента — §2):
     *   L1-50:   hp_seg1_base + L * hp_seg1_per   (default 100 + L*8 → L50=500)
     *   L50-180: продолжение по hp_seg2_per       (default +40/lvl → L150=4500, L180=5700)
     */
    public function healthForLevel(int $level): int
    {
        $level    = max(1, $level);
        $seg1Base = $this->gsInt('combat.nodes.hp_seg1_base', 100);
        $seg1Per  = $this->gsInt('combat.nodes.hp_seg1_per_level', 8);
        $seg1Cap  = $this->gsInt('combat.nodes.hp_seg1_cap_level', 50);
        $seg2Per  = $this->gsInt('combat.nodes.hp_seg2_per_level', 40);

        if ($level <= $seg1Cap) {
            return $seg1Base + $level * $seg1Per;
        }

        $seg1Total = $seg1Base + $seg1Cap * $seg1Per;

        return $seg1Total + ($level - $seg1Cap) * $seg2Per;
    }

    /**
     * Широтная полоса 0 (юг) .. 4 (глубокий север) — для группировки/per-band cap.
     */
    public function yBand(int $y): int
    {
        $y = max(0, min(self::MAP_MAX_Y, $y));

        return (int) floor((self::MAP_MAX_Y - $y) / 200); // 0..4
    }
}
