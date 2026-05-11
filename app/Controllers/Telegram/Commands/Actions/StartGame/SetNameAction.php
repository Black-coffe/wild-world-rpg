<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Services\Notifications\MediaSender;
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

        $message = "📝 *Правила выбора имени (чтобы понял даже тот, кто впервые в интернете)*:\n\n"
            . "1️⃣ *Длина:* от 3 до 20 символов.\n\n"
            . "2️⃣ *Разрешены только английские буквы* (A-Z, a-z), *цифры* (0-9) и *подчёркивание* (\_).\n"
            . "   ➡️ *Никаких* пробелов, спецсимволов, скобок, эмодзи, русских букв, мата, и т.д.\n\n"
            . "3️⃣ *Если твой «ник» нарушит эти правила — он не будет принят*.\n\n"
            . "☝️ *ВАЖНО!* 🤖 Чтобы я принял твоё имя, введи команду:\n"
            . "   `/name` (пробел) и затем _без кавычек_ впиши имя, например:\n"
            . "   `/name My_NewName123`\n\n"
            . "После этого я закреплю его за тобой!";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран-инструкция «введи /name ИМЯ» — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
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
