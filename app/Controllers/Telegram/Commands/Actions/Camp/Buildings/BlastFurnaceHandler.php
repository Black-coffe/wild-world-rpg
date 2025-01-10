<?php


namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class BlastFurnaceHandler extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Логика обработки для BlastFurnace
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => 'Вы выбрали Доменную Печь. Здесь вы можете... (дополните необходимой логикой)',
        ]);
    }

}