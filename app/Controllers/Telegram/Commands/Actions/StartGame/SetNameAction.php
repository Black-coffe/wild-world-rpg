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

        // N4 (ADR-039): вместо «введи /name ИМЯ» (slash рвёт inline-флоу) — forceReply.
        // Игрок отвечает на это сообщение именем; GenericmessageCommand ловит ответ
        // по маркеру «✍ NAME» → NameService->applyName. Зеркало трейд-паттерна SELL:/BUY:.
        $message = "📝 *Придумай имя герою* и пришли его *ответом на это сообщение*.\n\n"
            . "Правила: *3–20 символов*, только латиница (A-Z, a-z), цифры и «\_».\n"
            . "Без пробелов, кириллицы, эмодзи и спецсимволов.\n\n"
            . "Например: `My_Hero123`\n\n"
            . "_✍ NAME_";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'force_reply'            => true,
                'input_field_placeholder' => 'My_Hero123',
            ]),
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
