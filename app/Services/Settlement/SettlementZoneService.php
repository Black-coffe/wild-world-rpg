<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Models\SettlementModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-101 Фаза 0 — правила зоны поселения. Ядро safe/hostile-гейтов (встречи, авто-бой, PvP).
 *
 * policyAt(cell) → политика зоны ('safe'|'hostile'|'neutral') + поселение, ЕСЛИ клетка входит в
 * активное поселение (enabled=1) и слой включён (settlements.enabled). Иначе null (никаких правил).
 * Радиус зоны — settlements.zone_radius (метрика Чебышёва вокруг якоря; 0 = только якорная клетка).
 *
 * Фаза 0: сервис готов, но гейты в NpcEncounter/AutoPveHandler ещё не подключены (no-op до Фазы 1).
 */
final class SettlementZoneService
{
    private GameSettingsService $settings;
    private SettlementModel $settlements;

    public function __construct(?GameSettingsService $settings = null, ?SettlementModel $settlements = null)
    {
        $this->settings   = $settings ?? new GameSettingsService();
        $this->settlements = $settlements ?? new SettlementModel();
    }

    /** Включён ли слой поселений (killswitch). */
    public function layerEnabled(): bool
    {
        $v = $this->settings->get('settlements.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Радиус правил зоны вокруг якоря (Чебышёв; 0 = только якорь). */
    public function zoneRadius(): int
    {
        $v = $this->settings->get('settlements.zone_radius', 0);

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 0;
    }

    /**
     * Политика зоны для клетки. null если слой выключен или клетка не в активном поселении.
     *
     * @return array{policy:string, settlement:array<int|string,mixed>}|null
     */
    public function policyAt(int $cellNumber, ?int $x = null, ?int $y = null): ?array
    {
        if (! $this->layerEnabled()) {
            return null; // dormant — никаких правил
        }

        $radius = $this->zoneRadius();

        // Радиус 0 (или координаты не заданы): прямое совпадение по якорной клетке.
        if ($radius <= 0 || $x === null || $y === null) {
            $s = $this->settlements->activeByAnchorCell($cellNumber);
            return $s !== null ? ['policy' => $this->policyOf($s), 'settlement' => $s] : null;
        }

        // Радиус > 0: ближайшее активное поселение в пределах Чебышёв-радиуса от (x,y).
        $best     = null;
        $bestDist = $radius + 1;
        foreach ($this->settlements->allActive() as $s) {
            $sx = is_numeric($s['coordinate_x'] ?? null) ? (int) $s['coordinate_x'] : null;
            $sy = is_numeric($s['coordinate_y'] ?? null) ? (int) $s['coordinate_y'] : null;
            if ($sx === null || $sy === null) {
                continue;
            }
            $dist = max(abs($sx - $x), abs($sy - $y));
            if ($dist <= $radius && $dist < $bestDist) {
                $best     = $s;
                $bestDist = $dist;
            }
        }

        return $best !== null ? ['policy' => $this->policyOf($best), 'settlement' => $best] : null;
    }

    /** true если клетка — безопасная зона поселения (нельзя напасть на жителей / PvP-блок). */
    public function isSafeZone(int $cellNumber, ?int $x = null, ?int $y = null): bool
    {
        $p = $this->policyAt($cellNumber, $x, $y);

        return $p !== null && $p['policy'] === 'safe';
    }

    /**
     * @param array<int|string,mixed> $settlement
     */
    private function policyOf(array $settlement): string
    {
        $raw = is_string($settlement['zone_policy'] ?? null) ? $settlement['zone_policy'] : 'neutral';

        return in_array($raw, ['safe', 'hostile', 'neutral'], true) ? $raw : 'neutral';
    }
}
