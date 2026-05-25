<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\GameSettings\GameSettingsService;

/**
 * V19 (ADR-050) — ремонт роботов: восстановление durability текущего робота за
 * долю пропорциональной стоимости крафта (gold + resources).
 *
 * Закрывает мёртвое обещание здания RoboticsWorkshop («создавать И ремонтировать
 * роботов»). Роботы — расходники durability (V18/ADR-049): запуск тратит durability,
 * при 0 робот съедается из стака. Ремонт восстанавливает `durability_count` текущего
 * (частично израсходованного) робота до базового — устойчивый sink + maintenance-loop
 * дешевле перекрафта.
 *
 * Чистый калькулятор + GameSettings-reader (зеркало RobotService): cost_fraction и
 * killswitch live-tunable (category=resources, ADR-024).
 */
final class RobotRepairService
{
    private GameSettingsService $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->settings = $settings ?? new GameSettingsService();
    }

    /** Killswitch слоя ремонта. off → кнопка скрыта, действие заблокировано. */
    public function enabled(): bool
    {
        $v = $this->settings->get('robots.repair.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Доля от пропорциональной стоимости крафта за полное восстановление (<1 = дешевле перекрафта). */
    public function costFraction(): float
    {
        $v = $this->settings->get('robots.repair.cost_fraction', 0.6);
        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.6;
    }

    /**
     * Стоимость ремонта (gold + resources), пропорциональная восстанавливаемой durability.
     * `cost = ceil(recipeAmount × cost_fraction × restored/base)` для золота и каждого ресурса.
     * Компоненты (crafted_items под-детали) НЕ берутся — ремонт сырьём+работой, не пересборка.
     *
     * @param array<array-key,mixed> $recipe запись из Config\CraftRecipes (робота)
     * @return array{gold:int, resources:array<string,int>, restored:int}
     */
    public function computeCost(array $recipe, int $currentDur, int $baseDur): array
    {
        $restored = $baseDur - $currentDur;
        if ($restored <= 0 || $baseDur <= 0) {
            return ['gold' => 0, 'resources' => [], 'restored' => 0];
        }

        $frac    = $this->costFraction();
        $durFrac = $restored / $baseDur;

        $goldReqRaw = $recipe['gold_required'] ?? 0;
        $goldReq    = is_numeric($goldReqRaw) ? (int) $goldReqRaw : 0;
        $gold       = (int) ceil($goldReq * $frac * $durFrac);

        $resources    = [];
        $recipeResRaw = $recipe['resources'] ?? [];
        if (is_array($recipeResRaw)) {
            foreach ($recipeResRaw as $name => $amtRaw) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $amt  = is_numeric($amtRaw) ? (int) $amtRaw : 0;
                $cost = (int) ceil($amt * $frac * $durFrac);
                if ($cost > 0) {
                    $resources[$name] = $cost;
                }
            }
        }

        return ['gold' => $gold, 'resources' => $resources, 'restored' => $restored];
    }
}
