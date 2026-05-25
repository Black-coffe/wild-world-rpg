<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Robots;

/**
 * V18 (ADR-049) — preview крафта Робота-разведчика (T2 explorer).
 * Логика рендера — в RobotT2PreviewAction (из Config\CraftRecipes).
 */
class RobotScout2Action extends RobotT2PreviewAction
{
    protected function recipeKey(): string
    {
        return 'RobotScout';
    }
}
