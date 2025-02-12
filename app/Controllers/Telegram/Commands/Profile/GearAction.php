<?php

namespace app\Controllers\Telegram\Commands\Profile;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class GearAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1. Получаем данные пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден или персонаж не определён.',
            ]);
        }

        // 2. Формируем текстовое описание
        // (Можно добавить больше деталей, рассказать про слоты экипировки и т.д.)
        $text = "⚔️ *Раздел экипировки*\n\n"
            . "Ты находишься в своём *Арсенале* — здесь можно просмотреть:\n"
            . "• Что у тебя есть из брони/одежды\n"
            . "• Наличие оружия (ближнего и дальнего боя)\n"
            . "• Текущую экипировку: что уже надето на твоё тело\n\n"
            . "Выбери нужный пункт, чтобы увидеть подробности или изменить экипировку.\n\n"
            . "⚠️ *Внимание!* Убедись, что у тебя достаточно места, и помни о весе доспехов — "
            . "перегруз может негативно сказаться на выносливости.\n";

        // 3. Подготовим inline-кнопки
        // Пример: две строки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👕 Броня / Одежда', 'callback_data' => 'gearArmor'],
                    ['text' => '⚔️ Оружие',         'callback_data' => 'gearWeapons'],
                ],
            ]
        ];

        // 4. Путь к картинке (персонаж в доспехах/экипировке)
        $imagePath = base_url('uploads/telegram/gear/equipped_hero.png');
        // Подставьте реальный путь к вашей картинке

        // 5. Ответ на коллбек-запрос, чтобы убрать «часики»
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 6. Отправляем итоговое сообщение (фото + текст) игроку
        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}