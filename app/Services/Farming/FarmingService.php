<?php

declare(strict_types=1);

namespace App\Services\Farming;

use App\Services\GameSettings\GameSettingsService;

/**
 * V6 (ADR-033) — активное земледелие: семена → таймер роста → урожай + севооборот.
 *
 * Слой ПОВЕРХ пассивной теплицы (GreenhouseProductionHandler не тронут, 0 регрессии).
 * Все рычаги (killswitch, grow-времена, yields, rotation, slot-кап, drop-шанс) —
 * live-tunable через GameSettings (category=resources, ADR-024).
 *
 * Чистый/детерминированный сервис: вся математика тестируема (now/charId инжектятся
 * вызывающими, сам сервис — stateless reader GameSettings). RNG (seed-drop) живёт
 * в GatherTaskHandler, сюда передаётся готовый roll → testable.
 */
final class FarmingService
{
    /**
     * Карта культур: crop-key → метаданные (семя ↔ урожай 1:1) + ключи GameSettings.
     *
     * crop-key используется как:
     *  - callback-сегмент (plantSeedStart_<crop>),
     *  - значение characters.last_planted_crop,
     *  - task_settings.crop.
     *
     * @var array<string, array{seed_en:string, seed_ru:string, crop_en:string, crop_ru:string, icon:string, recipe:string, grow_key:string, grow_default:int, yield_key:string, yield_default:int}>
     */
    public const CROP_MAP = [
        'berries' => [
            'seed_en'      => 'BerrySeeds',
            'seed_ru'      => 'Семена ягод',
            'crop_en'      => 'Berries',
            'crop_ru'      => 'Ягоды',
            'icon'         => '🫐',
            'recipe'       => 'BerrySeeds',
            'grow_key'     => 'farming.grow.berries_minutes',
            'grow_default' => 30,
            'yield_key'    => 'farming.yield.berries',
            'yield_default' => 8,
        ],
        'mushrooms' => [
            'seed_en'      => 'MushroomSeeds',
            'seed_ru'      => 'Семена грибов',
            'crop_en'      => 'Mushrooms',
            'crop_ru'      => 'Грибы',
            'icon'         => '🍄',
            'recipe'       => 'MushroomSeeds',
            'grow_key'     => 'farming.grow.mushrooms_minutes',
            'grow_default' => 45,
            'yield_key'    => 'farming.yield.mushrooms',
            'yield_default' => 6,
        ],
        'fruit' => [
            'seed_en'      => 'FruitSeeds',
            'seed_ru'      => 'Семена фруктов',
            'crop_en'      => 'Fruit',
            'crop_ru'      => 'Фрукты',
            'icon'         => '🍎',
            'recipe'       => 'FruitSeeds',
            'grow_key'     => 'farming.grow.fruit_minutes',
            'grow_default' => 40,
            'yield_key'    => 'farming.yield.fruit',
            'yield_default' => 8,
        ],
        'crops' => [
            'seed_en'      => 'CropSeeds',
            'seed_ru'      => 'Семена овощей',
            'crop_en'      => 'Crops',
            'crop_ru'      => 'Зерновые культуры',
            'icon'         => '🌾',
            'recipe'       => 'CropSeeds',
            'grow_key'     => 'farming.grow.crops_minutes',
            'grow_default' => 60,
            'yield_key'    => 'farming.yield.crops',
            'yield_default' => 5,
        ],
    ];

    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    // ── killswitch & knobs ───────────────────────────────────────────────

    /** Killswitch активного слоя. false → меню/посадка/сид-крафт скрыты (пассив жив). */
    public function isEnabled(): bool
    {
        return $this->boolSetting('farming.enabled', true);
    }

    public function rotationEnabled(): bool
    {
        return $this->boolSetting('farming.rotation.enabled', true);
    }

    public function rotationBonusPercent(): int
    {
        return max(0, $this->intSetting('farming.rotation.bonus_percent', 20));
    }

    public function maxConcurrentPlantings(): int
    {
        return max(1, $this->intSetting('farming.max_concurrent_plantings', 2));
    }

    public function seedDropChance(): float
    {
        $v = $this->floatSetting('farming.seed_drop_chance', 0.05);
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }
        return $v;
    }

    // ── crop math ────────────────────────────────────────────────────────

    /** @return list<string> ключи культур (berries/mushrooms/fruit/crops). */
    public function allCrops(): array
    {
        return array_keys(self::CROP_MAP);
    }

    public function isCrop(string $crop): bool
    {
        return isset(self::CROP_MAP[$crop]);
    }

    /**
     * @return array{seed_en:string, seed_ru:string, crop_en:string, crop_ru:string, icon:string, recipe:string, grow_key:string, grow_default:int, yield_key:string, yield_default:int}|null
     */
    public function cropMeta(string $crop): ?array
    {
        return self::CROP_MAP[$crop] ?? null;
    }

    /** crop-key по name_en семени (обратный lookup для seed-drop / select). */
    public function cropForSeedNameEn(string $seedEn): ?string
    {
        foreach (self::CROP_MAP as $crop => $meta) {
            if ($meta['seed_en'] === $seedEn) {
                return $crop;
            }
        }
        return null;
    }

    /**
     * Время роста культуры в минутах (live-tunable).
     * $extraMult — внешний множитель (V7: greenhouse grow_time_multiplier по уровню
     * теплицы; default 1.0 = нет эффекта). Min 1 минута.
     */
    public function growMinutes(string $crop, float $extraMult = 1.0): int
    {
        $meta = self::CROP_MAP[$crop] ?? null;
        if ($meta === null) {
            return 0;
        }
        $base = max(1, $this->intSetting($meta['grow_key'], $meta['grow_default']));
        $mult = $extraMult > 0 ? $extraMult : 1.0;
        return max(1, (int) round($base * $mult));
    }

    /** Базовый урожай культуры (до бонуса севооборота). */
    public function baseYield(string $crop): int
    {
        $meta = self::CROP_MAP[$crop] ?? null;
        if ($meta === null) {
            return 0;
        }
        return max(1, $this->intSetting($meta['yield_key'], $meta['yield_default']));
    }

    /**
     * Итоговый урожай с учётом бонуса севооборота (детерминированно).
     * $rotation — решение, зафиксированное при старте посадки (crop != last).
     * $extraMult — внешний множитель (V7: greenhouse harvest_yield_multiplier по
     * уровню теплицы; default 1.0 = нет эффекта). Применяется поверх севооборота.
     */
    public function harvestYield(string $crop, bool $rotation, float $extraMult = 1.0): int
    {
        $base = $this->baseYield($crop);
        if ($base <= 0) {
            return 0;
        }
        $factor = 1.0;
        if ($rotation && $this->rotationEnabled()) {
            $factor = 1.0 + ($this->rotationBonusPercent() / 100.0);
        }
        $factor *= $extraMult > 0 ? $extraMult : 1.0;
        return max(1, (int) round($base * $factor));
    }

    /**
     * Применяется ли севооборот: культура отличается от прошлой посаженной.
     * Первая посадка (нет истории) — baseline (нечего «чередовать»).
     */
    public function shouldRotate(?string $lastCrop, string $crop): bool
    {
        if (! $this->rotationEnabled()) {
            return false;
        }
        return $lastCrop !== null && $lastCrop !== '' && $lastCrop !== $crop;
    }

    // ── internals ────────────────────────────────────────────────────────

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
