<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\NameService;

class NameCommand extends UserCommand
{
    protected $name = 'name';
    protected $description = 'Sets a new name for the character';
    protected $usage = '/name <new_name>';
    protected $version = '1.3.0';

    public function execute(): ServerResponse
    {
        $message    = $this->getMessage();
        $chatId     = $message->getChat()->getId();
        $telegramId = $message->getFrom()->getId();

        // Извлекаем желаемое имя (после /name)
        $commandText = trim($message->getText() ?? '');
        $name        = trim(str_ireplace('/name', '', $commandText));

        if ($name === '') {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "❌ Пожалуйста, введите новое имя после команды `/name`.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $existingUser = (new TelegramUserModel())->asArray()->where('telegram_id', $telegramId)->first();
        if (!is_array($existingUser)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Ошибка: пользователь не найден в базе.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $character = (new CharacterModel())->asArray()->where('telegram_user_id', $existingUser['id'])->first();
        if (!is_array($character)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "Ошибка: персонаж не найден.",
                'parse_mode' => 'Markdown',
            ]);
        }

        // N4 (ADR-039): валидация + tier-логика смены имени вынесены в NameService —
        // общий источник правды со ForceReply-вводом имени в онбординге
        // (GenericmessageCommand::handleNameReply ← SetNameAction forceReply).
        $result = (new NameService())->applyName($character, $name);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $result['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $result['keyboard'] !== null ? json_encode($result['keyboard']) : null,
        ]);
    }
}
