<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\CharacterService;
use App\Models\TelegramUserModel;
use App\Models\CharacterModel;

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $text    = trim($message->getText(true));
        $chatId  = $message->getChat()->getId();

        switch (mb_strtolower($text)) {
            case 'персонаж':
                return $this->handleCharacter($chatId);

            case 'база':
                // Пока пусто
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Вы запросили «база». Логика пока не написана.',
                ]);

            case 'крафт':
                // Пока пусто
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Вы запросили «крафт». Логика пока не написана.',
                ]);

            default:
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Не понял, попробуйте «персонаж», «база» или «крафт».',
                ]);
        }
    }

    private function handleCharacter(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        // Ищем юзера
        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден.',
            ]);
        }

        // Ищем персонажа
        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден.',
            ]);
        }

        // Вызываем сервис
        $charService = new CharacterService();
        return $charService->showCharacterInfo($chatId, $characterRow);
    }
}
