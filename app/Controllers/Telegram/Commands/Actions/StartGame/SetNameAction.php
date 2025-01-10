<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SetNameAction extends BaseAction
{
    protected $characterModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $message = "📝 *Правила выбора имени:*\n"
            . "🆔 Длина: от 3 до 20 символов.\n"
            . "🔤 Разрешены: латинские буквы, цифры и знаки подчёркивания (\_).\n"
            . "🚫 Запрещены: пробелы, специальные символы и нецензурная лексика.\n\n"
            . "☝️ ВАЖНО: 🤖 Чтобы я  понял твое имя вводи его так `/name` и после пробела придуманное имя!\n\n"
            . "Напиши `/name` и после пробела далее введи новое имя в чат и отправь мне, чтобы я закрепил его за тобой";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        ]);
    }

    public function setName(string $name): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Проверяем, что имя соответствует правилам
        if (preg_match('/^[a-zA-Z0-9_]{3,20}$/', $name)) {
            // Обновляем имя персонажа
            $this->characterModel->update($character['id'], ['name' => $name]);

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "🎉 Имя вашего персонажа успешно обновлено: *{$name}*!",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Если имя не соответствует правилам, отправляем ошибку
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => "❌ Имя не соответствует правилам. Проверьте длину или используемые символы.",
            'parse_mode' => 'Markdown',
        ]);
    }
}
