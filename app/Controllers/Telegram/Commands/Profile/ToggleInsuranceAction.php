<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ToggleInsuranceAction extends BaseAction
{
    protected $characterModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
    }

    public function handle(): ServerResponse
    {
        // Получаем данные из callback-запроса
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // 2. Переключаем значение страховки
        $currentInsuranceStatus = $character['insurance'];
        $newInsuranceStatus = !$currentInsuranceStatus;  // Меняем на противоположное

        // Обновляем значение в базе данных
        $this->characterModel->update($character['id'], ['insurance' => $newInsuranceStatus]);

        // 3. Формируем текст сообщения
        $messageText = $newInsuranceStatus
            ? "✅ Страховка успешно включена."
            : "❌ Страховка успешно отключена.";

        // 4. Отправляем сообщение
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
        ]);
    }
}
