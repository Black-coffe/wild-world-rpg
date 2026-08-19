<?php

declare(strict_types=1);

namespace App\Services\World;

use App\Services\GameSettings\GameSettingsService;

/**
 * Транспорт (транспортная система, story transport-02) — тонкий ридер профиля машины.
 *
 * Профиль — плоский массив (контракт `docs/specs/transport-system/plan.md → ## Contracts`):
 * `['key','cells_per_tick','tired_factor','max_steps_per_order','cargo_share','wear_per_cell']`.
 * Killswitch `world.vehicle.enabled` (default false) держит фичу DORMANT: при выключенном
 * килсвитче, `null`-ключе машины или неизвестном ключе — всегда {@see neutralProfile()}, без
 * исключений. `neutralProfile()` читает базовые значения похода из `world.march.*`, поэтому
 * поведение до включения транспорта байт-идентично прежнему.
 *
 * Тест-сим: 2-й аргумент конструктора $overrides (map key→value) читается вместо GameSettings —
 * чистый unit-тест логики без БД (паттерн «чистая логика без mock'ов», ADR-131/138, см.
 * {@see \App\Services\Player\Progression\EarlyProgressionService}).
 *
 * Не читает `characters.active_vehicle_log_id` и вообще БД персонажа — разрешение указателя
 * (какая машина активна у кого) делает `VehicleActivationService` (story transport-03).
 *
 * @see \App\Database\Migrations\SeedVehicleGameSettings
 */
final class VehicleEffectsService
{
    public const TERRAIN_EXPLORED   = 'explored';
    public const TERRAIN_UNEXPLORED = 'unexplored';
    public const TERRAIN_COLD       = 'cold';

    /** Единые ключи машин — GameSettings, профиль, ImageRegistry (plan.md → ## Contracts). */
    private const VEHICLE_KEYS = ['cart', 'mtb', 'snowmobile', 'draft_cart', 'drone_auto'];

    private const HARD_MAX_CELLS_PER_TICK = 5;

    private ?GameSettingsService $settings;

    /** @var array<string, mixed>|null тест-оверрайды (минуя GameSettings). */
    private ?array $overrides;

    /**
     * @param array<string, mixed>|null $overrides
     */
    public function __construct(?GameSettingsService $settings = null, ?array $overrides = null)
    {
        $this->settings  = $settings;
        $this->overrides = $overrides;
    }

    /** Мастер-killswitch транспорта. */
    public function isEnabled(): bool
    {
        return $this->toBool($this->rawSetting('world.vehicle.enabled', false));
    }

    /**
     * Нейтральный профиль — пешеход. Ключ `null`, скорость/приказ читают базу похода
     * (`world.march.*`), усталость/груз/износ без бонуса/штрафа.
     *
     * @return array{key: null, cells_per_tick: int, tired_factor: float, max_steps_per_order: int, cargo_share: float, wear_per_cell: int}
     */
    public function neutralProfile(): array
    {
        return [
            'key'                 => null,
            'cells_per_tick'      => $this->baseCellsPerTick(),
            'tired_factor'        => 1.0,
            'max_steps_per_order' => $this->baseMaxStepsPerOrder(),
            'cargo_share'         => 0.0,
            'wear_per_cell'       => 0,
        ];
    }

    /**
     * Профиль машины для класса местности. Килсвитч off, `$vehicleKey===null` или
     * неизвестный ключ → {@see neutralProfile()} без исключения.
     *
     * @return array{key: ?string, cells_per_tick: int, tired_factor: float, max_steps_per_order: int, cargo_share: float, wear_per_cell: int}
     */
    public function profileFor(?string $vehicleKey, string $terrain): array
    {
        if ($vehicleKey === null || ! $this->isEnabled() || ! in_array($vehicleKey, self::VEHICLE_KEYS, true)) {
            return $this->neutralProfile();
        }

        $prefix   = "world.vehicle.{$vehicleKey}.";
        $cellsKey = match ($terrain) {
            self::TERRAIN_EXPLORED => $prefix . 'cells_per_tick_explored',
            self::TERRAIN_COLD     => $prefix . 'cells_per_tick_cold',
            default                => $prefix . 'cells_per_tick_unexplored',
        };

        $base  = $this->baseCellsPerTick();
        $cells = $this->intSetting($cellsKey, $base);
        $cells = max($base, min(self::HARD_MAX_CELLS_PER_TICK, $cells));

        $cargoMax = $this->floatSetting('world.vehicle.cargo.max_share', 0.33);
        $cargo    = $this->floatSetting($prefix . 'cargo_share', 0.0);
        $cargo    = max(0.0, min($cargoMax, $cargo));

        return [
            'key'                 => $vehicleKey,
            'cells_per_tick'      => $cells,
            'tired_factor'        => $this->floatSetting($prefix . 'tired_factor', 1.0),
            'max_steps_per_order' => $this->intSetting($prefix . 'max_steps_per_order', $this->baseMaxStepsPerOrder()),
            'cargo_share'         => $cargo,
            'wear_per_cell'       => $this->intSetting($prefix . 'wear_per_cell', 0),
        ];
    }

    // ── internals ───────────────────────────────────────────────────────

    private function baseCellsPerTick(): int
    {
        return $this->intSetting('world.march.cells_per_tick', 3);
    }

    private function baseMaxStepsPerOrder(): int
    {
        return $this->intSetting('world.march.max_steps_per_order', 60);
    }

    private function rawSetting(string $key, mixed $default): mixed
    {
        if ($this->overrides !== null) {
            return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : $default;
        }
        $this->settings ??= new GameSettingsService();

        return $this->settings->get($key, $default);
    }

    private function intSetting(string $key, int $activeDefault): int
    {
        $raw = $this->rawSetting($key, $activeDefault);

        return is_numeric($raw) ? (int) $raw : $activeDefault;
    }

    private function floatSetting(string $key, float $activeDefault): float
    {
        $raw = $this->rawSetting($key, $activeDefault);

        return is_numeric($raw) ? (float) $raw : $activeDefault;
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
