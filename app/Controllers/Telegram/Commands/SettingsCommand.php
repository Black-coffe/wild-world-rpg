<?php

namespace App\Controllers\Telegram\Commands;

use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * `/settings` — экран настроек (пока: тумблер «Картинки в сообщениях», идея #14).
 * Содержимое экрана собирает {@see SettingsAction::buildScreen()} (общий рендер
 * с callback-handler'ом `settings`/`mediaOn`/`mediaOff` и текстом «настройки»).
 */
class SettingsCommand extends UserCommand
{
    protected $name        = 'settings';
    protected $description = 'Settings (toggle images in messages)';
    protected $usage       = '/settings';
    protected $version     = '1.0';

    public function execute(): ServerResponse
    {
        $message    = $this->getMessage();
        $chatId     = $message->getChat()->getId();
        $telegramId = (int) $message->getFrom()->getId();

        /** @var array<string,mixed>|null $userRow */
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if ($userRow === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Telegram-пользователь не найден. Используйте /start.',
            ]);
        }

        /** @var CharacterEntity|null $character */
        $character = (new CharacterModel())->where('telegram_user_id', $userRow['id'] ?? 0)->first();
        if ($character === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден. Используйте /start.',
            ]);
        }

        return Request::sendMessage(['chat_id' => $chatId] + SettingsAction::buildScreen($character));
    }
}
