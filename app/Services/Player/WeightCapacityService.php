<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * W3a (ADR-059) — Cargo drone + weight-cap foundation. Pure GameSettings-reader +
 * compute (зеркало DroneService паттерна).
 *
 * Soft-cap веса инвентаря персонажа (`character_resources` raw resources only;
 * gold/crafted_items exempt — резолюция Q4 ADR-059). На ship default
 * `inventory.weight_cap.enabled = false` → механика выключена.
 *
 * Балансировочные knob'ы (live-tunable, ADR-024, category=resources):
 *  - inventory.weight_cap.enabled (bool, default false = killswitch off)
 *  - inventory.weight_cap.l1_base (int, default 100 kg)
 *  - inventory.weight_cap.per_level (int, default 3 kg/level)
 *
 * Формула: `cap(level) = l1_base + (level - 1) × per_level`. L1=100/L100=397kg.
 *
 * W3b (Cargo drone) использует `getRemainingCapacity()` как gate для launch
 * (canLaunchCargo(charge, payload_weight) ∧ payload_weight ≤ remaining).
 */
final class WeightCapacityService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * Killswitch всей weight-cap механики. false → gather проходит без cap-check,
     * UI «Склад» скрыт, cargo-drone gate отключён (но launch всё ещё работает).
     */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('inventory.weight_cap.enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Базовый weight_capacity для L1 персонажа (kg). Default 100.
     */
    public function l1Base(): int
    {
        $v = $this->settings->get('inventory.weight_cap.l1_base', 100);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : 100;
    }

    /**
     * Прирост weight_capacity за каждый level (kg). Default 3.
     */
    public function perLevel(): int
    {
        $v = $this->settings->get('inventory.weight_cap.per_level', 3);
        return is_numeric($v) && (int) $v >= 0 ? (int) $v : 3;
    }

    /**
     * Compute weight_capacity для персонажа уровня $level.
     * Формула: `cap = l1_base + (level - 1) × per_level`.
     *
     * @param int $level character level (>= 1)
     */
    public function computeCapacity(int $level): int
    {
        if ($level < 1) {
            $level = 1;
        }
        return $this->l1Base() + ($level - 1) * $this->perLevel();
    }

    /**
     * Текущая загрузка персонажа (сумма weight × quantity по character_resources).
     * Возвращает kg в DECIMAL-precision (0.05 + 0.05 + ... = float).
     *
     * Gold и crafted_items НЕ учитываются (резолюция Q4 ADR-059).
     *
     * @param int $characterId
     */
    public function getCurrentLoad(int $characterId): float
    {
        $db    = \Config\Database::connect();
        $query = $db->query(
            'SELECT COALESCE(SUM(r.weight * cr.quantity), 0) AS total_kg
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ?',
            [$characterId]
        );
        if (! is_object($query) || ! method_exists($query, 'getRowArray')) {
            return 0.0;
        }
        $row = $query->getRowArray();
        if (! is_array($row)) {
            return 0.0;
        }
        $raw = $row['total_kg'] ?? 0;
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Сколько kg ещё можно добавить в инвентарь персонажа до cap.
     * Если killswitch off → PHP_INT_MAX (effectively unlimited).
     * Возвращает 0 если уже над cap (overflow ожидается только из legacy state).
     */
    public function getRemainingCapacity(int $characterId, int $characterLevel, int $weightCapacity): float
    {
        if (! $this->isEnabled()) {
            return (float) PHP_INT_MAX;
        }
        $current = $this->getCurrentLoad($characterId);
        $cap     = $weightCapacity > 0 ? $weightCapacity : $this->computeCapacity($characterLevel);
        $remain  = $cap - $current;
        return $remain > 0 ? $remain : 0.0;
    }

    /**
     * Можно ли добавить $additionalKg в инвентарь без overflow cap.
     * Если killswitch off → true (no gate).
     */
    public function canAdd(int $characterId, int $characterLevel, int $weightCapacity, float $additionalKg): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }
        return $this->getRemainingCapacity($characterId, $characterLevel, $weightCapacity) >= $additionalKg;
    }
}
