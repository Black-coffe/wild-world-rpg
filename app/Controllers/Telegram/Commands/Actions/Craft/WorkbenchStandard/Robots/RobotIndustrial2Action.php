<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

/**
 * V18 (ADR-049) — preview крафта Робота-промышленника (T2 gatherer).
 * Логика рендера — в RobotT2PreviewAction (из Config\CraftRecipes).
 */
class RobotIndustrial2Action extends RobotT2PreviewAction
{
    protected function recipeKey(): string
    {
        return 'RobotIndustrial';
    }
}
