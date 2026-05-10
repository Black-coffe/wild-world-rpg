<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ArmorCraft2Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Текст с кратким описанием раздела брони (добавляем тематические эмоджи)
        $text = "*Ты в разделе 🛡️ Броня!* 🏭\n\n"
            . "Раздел крафта различных защитных элементов.\n\n"
            . "_Ниже — доступные варианты для создания._\n"
            . "Выбирай нужную позицию и приступай к крафту:\n\n"
            . "1) 👕 *Рваная рубаха*\n"
            . "2) 🧥 *Одежда бродяги*\n"
            . "3) 🧥 *Кожаная куртка*\n"
            . "4) 🪡 *Усиленная кожаная куртка*\n"
            . "5) 🔩 *Нагрудник из металлолома*\n"
            . "6) 🪖 *Солдатский бронежилет (старый)*\n"
            . "7) 🦺 *Охотничий жилет*\n"
            . "8) 🤺 *Полный латный доспех (ржавый)*\n"
            . "9) 🏴 *Броня наёмника*\n"
            . "10) 🥷 *Самурайский доспех (О-ёрой)*\n";

        // Формируем кнопки для выбора каждой брони (добавляем эмоджи в названия)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👕 Рваная рубаха',        'callback_data' => 'armorRaggedShirt'],
                    ['text' => '🧥 Одежда бродяги',       'callback_data' => 'armorDrifterClothes'],
                ],
                [
                    ['text' => '🧥 Кожаная куртка',       'callback_data' => 'armorLeatherJacket'],
                    ['text' => '🪡 Усил. кож. куртка',    'callback_data' => 'armorReinforcedLeather'],
                ],
//                [
//                    ['text' => '🔩 Нагр. из металлолома', 'callback_data' => 'armorScrapChestplate'],
//                    ['text' => '🪖 Ст. арм. бронежилет',  'callback_data' => 'armorOldArmyVest'],
//                ],
//                [
//                    ['text' => '🦺 Охотничий жилет',      'callback_data' => 'armorHunterVest'],
//                    ['text' => '🤺 Полный латный (ржав.)','callback_data' => 'armorRustyPlate'],
//                ],
//                [
//                    ['text' => '🏴 Броня наёмника',       'callback_data' => 'armorMercArmor'],
//                    ['text' => '🥷 Самурайский доспех',   'callback_data' => 'armorSamuraiArmor'],
//                ],
            ]
        ];

        // Иллюстрация для брони (при необходимости можно заменить на другое изображение)
        $imagePath = base_url('uploads/telegram/craft/standard/all_armor.jpg');

        // Ответ на callback, чтобы убрать "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Отправляем сообщение с фото и клавиатурой
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
