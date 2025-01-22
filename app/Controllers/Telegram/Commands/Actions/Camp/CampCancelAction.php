<?php

namespace app\Controllers\Telegram\Commands\Actions\Camp;

class CampCancelAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => 'Создание лагеря отменено.'
        ]);
    }

}