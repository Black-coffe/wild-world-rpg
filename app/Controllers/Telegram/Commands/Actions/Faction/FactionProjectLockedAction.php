<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * CLAUDE.md §🎮 UX-DISCOVERABILITY — lock-state кнопки «🔒 Проект фракции».
 *
 * Когда фича включена (`faction.project.enabled=true`) но игрок не в фракции,
 * в Персонаже отображается lock-кнопка вместо «Проект фракции». Клик по ней
 * приводит сюда: alert объясняет prerequisite, чтобы игрок понимал откуда
 * взять доступ — а не оставался в неведении после tip/анонса.
 *
 * Lvl < 10: «дорасти и выбрать фракцию».
 * Lvl ≥ 10: «выбери фракцию через ⚑ Выбрать фракцию в Персонаже».
 */
final class FactionProjectLockedAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        $level = (int) ($character['level'] ?? 0);
        $msg   = $level >= 10
            ? "🔒 Проект фракции открывается после выбора фракции.\n\nЗайди в " . \App\Services\Telegram\BotMenuService::menuLabel('me') . " и нажми ⚑ Выбрать фракцию — она появилась с 10 уровня."
            : "🔒 Проект фракции доступен с 10 уровня — после выбора фракции.\n\nСейчас твой уровень {$level}. Качайся: разведка, добыча, крафт, бой — всё даёт XP.";

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
            'cache_time'        => 0,
        ]);
    }
}
