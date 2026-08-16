<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E7 (ROADMAP-100-SESSIONS) — lock-state кнопки «⚑ Фракция (с lvl 10)» для игрока < L10.
 *
 * CLAUDE.md §🎮 UX-DISCOVERABILITY + срез E7: 62% доросших до L10 выбирают фракцию, но
 * до L10 цель была НЕВИДИМА (кнопка появлялась только с 10 уровня). Lock-кнопка сажает
 * цель РАНО: новичок видит, что его ждёт выбор стороны, и зачем к нему стремиться
 * (value-prop teaser) — это ранняя мотивация, а не молчаливое скрытие.
 *
 * Клик → alert: текущий уровень + сколько осталось до L10 + что даёт фракция.
 */
final class ChooseFactionLockedAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        // Alert-popup: ≤200 символов, Markdown НЕ рендерится → плоский текст.
        $level = (int) ($character['level'] ?? 0);
        $left  = max(0, 10 - $level);

        $msg = "🔒 Выбор фракции — с 10 уровня (у тебя {$level}, осталось {$left}). "
            . "Фракция даёт легендарное оружие/броню, −10% к крафту своим и скидки караванов. "
            . "Качайся: разведка, добыча, крафт, бой дают XP.";

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
            'cache_time'        => 0,
        ]);
    }
}
