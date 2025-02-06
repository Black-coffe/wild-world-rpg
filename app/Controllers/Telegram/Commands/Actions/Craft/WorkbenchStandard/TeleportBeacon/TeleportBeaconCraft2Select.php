<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Аналог класса RobotsCraft2Select,
 * но для раздела "Телепорт маяки".
 *
 * Пока у нас один тип маяка, но
 * в будущем можно будет добавить другие (PVP-маяк, маяк-слежки и т.д.).
 */
class TeleportBeaconCraft2Select extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Получаем chat_id, чтобы отправить сообщение
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Текст, объясняющий, что мы в разделе крафта маяков
        $text = "*Ты в разделе 🌀 Телепортов!* 🏭\n\n"
            . "Здесь ты можешь создать специальный предмет — *маяк телепорта*. А также *рюкзак телепорт*\n"
            . "В будущем появятся другие типы маяков (для слежки, обнаружения и т.д.).\n\n"
            . "_Сейчас доступно только два варианта:_\n"
            . "• *Телепорт-маяк (базовый)*\n"
            . "• *Рюкзак-телепорт*\n\n"
            . "Для крафта нужно иметь:\n"
            . "1. Построенный лагерь (базу)\n"
            . "2. 1-й верстак (WorkbenchOne)\n"
            . "3. Достаточно ресурсов (укажем в следующем шаге)\n"
            . "4. Уровень персонажа ≥ 12\n\n"
            . "Выбери тип телепорта и приступай к крафту!";

        // Кнопки
        // Здесь всего один маяк, но в будущем можно расширить.
        $keyboard = [
            'inline_keyboard' => [
                [
                    // Кнопка, которая будет вести к классу "подтверждения" и реального крафта
                    ['text' => '🌀 Базовый телепорт-маяк', 'callback_data' => 'teleportBeaconBasic2'],
                ],
                [
                    // Кнопка, которая будет вести к классу "подтверждения" и реального крафта
                    ['text' => '🎒 Рюкзак телепорт', 'callback_data' => 'teleportBackpack2'],
                ],
            ]
        ];

        // Допустим, картинка с маяком
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_select.jpg');

        // Убираем часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
