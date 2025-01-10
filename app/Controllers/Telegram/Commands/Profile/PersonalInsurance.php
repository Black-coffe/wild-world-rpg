<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class PersonalInsurance extends BaseAction
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

        // 2. Проверяем значение поля insurance
        $hasInsurance = $character['insurance'];

        // 3. Формируем текст сообщения
        $text = "👤 *Персонаж: {$character['name']}*\n\n";
        $text .= $hasInsurance
            ? "✅ У вашего персонажа *есть* страховка от смерти.\n\n"
            : "❌ У вашего персонажа *нет* страховки от смерти.\n\n";

        $text .= "🛡 *Информация о страховке:*\n";
        $text .= "• Включенная страховка позволяет в случае смерти избежать полного обнуления всего, что есть у персонажа.\n";
        $text .= "• Сохраняется прокачка, инвентарь и все остальное.\n";
        $text .= "• При смерти персонаж просто возродится на своей базе.\n\n";
        $text .= "💰 *Стоимость страховки:*\n";
        $text .= "• Рассчитывается автоматически для каждого игрока.\n";
        $text .= "• Уникальная цена определяется в момент смерти.\n";
        $text .= "• Зависит от множества факторов и обстоятельств.\n";

        // Формируем клавиатуру с кнопками
        $keyboard = [
            'inline_keyboard' => [
                [

                    ['text' => $hasInsurance ? 'Снять страховку' : 'Страховаться', 'callback_data' => 'toggleInsurance'],
                    ['text' => '🧮 Просчет', 'callback_data' => 'calculateInsurance'],
                    ['text' => '🔙 Персонаж', 'callback_data' => 'character'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // Отправляем сообщение с информацией и клавиатурой
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
