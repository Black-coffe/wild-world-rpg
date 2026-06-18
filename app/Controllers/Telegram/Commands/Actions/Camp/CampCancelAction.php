<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Отмена создания лагеря (callback `CancelCamp`).
 *
 * 2026-06-18: класс был сломан (не extends BaseAction, без use-импортов → фаталился
 * при вызове). Раньше не стрелял — кнопки `CancelCamp` нигде не было. Починен и
 * подключён как «↩️ Не сейчас» на экране подтверждения постановки лагеря.
 */
class CampCancelAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => 'Лагерь не разбит. Когда будешь готов — снова жми «🏕️ Окопаться».',
        ]);
    }
}
