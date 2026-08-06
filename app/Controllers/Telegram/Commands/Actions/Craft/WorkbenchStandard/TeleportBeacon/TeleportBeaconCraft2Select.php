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

        $scope = new \App\Services\Tasks\ActionScopeService();

        // Три устройства с РАЗНЫМ смыслом — их и разводим текстом, иначе игрок не понимает,
        // чем маяк отличается от рюкзака (живой вопрос игрока 2026-08-06).
        $text = "*Ты в разделе 🌀 Телепортов!* 🏭\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "Три устройства, и они про разное:\n"
            . "• 🌀 *Телепорт-маяк* — ставится в выбранной точке мира и работает как "
            . "*точка возврата*: прыгаешь к нему откуда угодно. Ставить и прыгать — "
            . "в «🏠 База» → «📡 Маяки».\n"
            . "• 🎒 *Рюкзак-телепорт* — возврат *на свою базу*, срабатывает раз в час.\n"
            . "• 📡 *Портативный телепорт* — тоже возврат на базу, но *без ожидания*: "
            . "хоть дважды подряд. Зарядов мало, сборка дороже.\n\n"
            . "Для сборки нужны база, Центр телепортации и 1-й верстак; "
            . "материалы и требования по уровню — на экране каждого устройства.\n\n"
            . "Выбирай, что собираем.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🌀 Базовый телепорт-маяк', 'callback_data' => 'teleportBeaconBasic2'],
                ],
                [
                    ['text' => '🎒 Рюкзак телепорт', 'callback_data' => 'teleportBackpack2'],
                ],
                [
                    ['text' => '📡 Портативный телепорт', 'callback_data' => 'portableTeleport2'],
                ],
            ]
        ];

        // Допустим, картинка с маяком
        $imagePath = base_url('uploads/telegram/craft/standard/beacon_select.jpg');

        // Убираем часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
