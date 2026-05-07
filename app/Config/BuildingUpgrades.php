<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * v0.51.61 (UpgradeBuildingAction decomp Step 4) — extract upgrade requirements
 * config (gold + character_level + resources per target level) с inline-property
 * у Action на dedicated Config class.
 *
 * Структура: $requirements[targetLevel] = ['gold' => int, 'level' => int, 'resources' => array<string,int>]
 * де targetLevel ∈ [2..10].
 *
 * Pattern: GameBalance F2.10 — централізована конфіг, можна override через .env
 * для test rebalance, готує ground для майбутнього БД-driven hot-reload.
 *
 * Source of truth до v0.51.60: UpgradeBuildingAction::\$upgradeRequirements
 * (lines 44-117 у legacy version).
 */
class BuildingUpgrades extends BaseConfig
{
    public const MAX_LEVEL = 10;

    /**
     * Требования для перехода на каждый уровень (2..10):
     * - gold     = нужное количество золота
     * - level    = минимальный уровень персонажа
     * - resources= массив необходимых ресурсов (name_en => кол-во)
     *
     * @var array<int, array{gold: int, level: int, resources: array<string, int>}>
     */
    public array $requirements = [
        2 => [
            'gold'      => 50000,
            'level'     => 1,
            'resources' => [],
        ],
        3 => [
            'gold'      => 75000,
            'level'     => 12,
            'resources' => [],
        ],
        4 => [
            'gold'      => 100000,
            'level'     => 14,
            'resources' => [
                'Water' => 15000,
                'Wood'  => 10000,
            ],
        ],
        5 => [
            'gold'      => 150000,
            'level'     => 20,
            'resources' => [
                'Water'  => 15000,
                'Wood'   => 15000,
                'Pebble' => 15000,
            ],
        ],
        6 => [
            'gold'      => 200000,
            'level'     => 22,
            'resources' => [
                'Water'  => 18000,
                'Wood'   => 20000,
                'Pebble' => 22000,
                'Sand'   => 15000,
            ],
        ],
        7 => [
            'gold'      => 300000,
            'level'     => 24,
            'resources' => [
                'Water'  => 24000,
                'Wood'   => 30000,
                'Pebble' => 32000,
                'Sand'   => 28000,
            ],
        ],
        8 => [
            'gold'      => 368000,
            'level'     => 26,
            'resources' => [
                'Water'  => 28200,
                'Wood'   => 36400,
                'Pebble' => 34800,
                'Sand'   => 31400,
            ],
        ],
        9 => [
            'gold'      => 472000,
            'level'     => 28,
            'resources' => [
                'Water'  => 31200,
                'Wood'   => 39340,
                'Pebble' => 37150,
                'Sand'   => 34120,
            ],
        ],
        10 => [
            'gold'      => 1000000,
            'level'     => 30,
            'resources' => [],
        ],
    ];
}
