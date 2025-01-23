<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\BaseService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ShowBaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден, не могу показать базу.',
            ]);
        }
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден, не могу показать базу.',
            ]);
        }

        // Вызываем сервис базы
        $baseService = new BaseService();
        return $baseService->showBaseInfo($chatId, $character);
    }
}