<?php
namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\BaseService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class CampShowCreationAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1. Снятие "часиков" на кнопке Telegram (если надо)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 2. Достаём $chatId и персонажа
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        // 3. Запускаем BaseService::showCampCreation()
        $baseService = new BaseService();
        return $baseService->showCampCreation($chatId, $character);
    }
}
