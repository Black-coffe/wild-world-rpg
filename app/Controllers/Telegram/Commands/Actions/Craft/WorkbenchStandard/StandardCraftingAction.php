<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class StandardCraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*Ты в разделе 🔧 Стандартный крафт* 🏭\n\n"
            . "В этом разделе можно крафтить *продвинутые вещи*!\n"
            . "Все, что внизу можно крафтить имея *1й верстак* и *построенную базу*!\n\n"
            . "_Выбирай направление крафта и если у тебя достаточно ресурсов, ты получишь нужную вещь_ 👇\n";

        // Базовые категории.
        $rows = [
            [
                ['text' => '🤖 Роботы',    'callback_data' => 'robotsCraft2'],
                ['text' => '🌀 Телепорты', 'callback_data' => 'teleportBeaconCraft2'],
            ],
            [
                ['text' => '🛡️ Броня',     'callback_data' => 'armorCraft2'],
                ['text' => '⚔️ Оружие',    'callback_data' => 'weaponsCraft2'],
            ],
        ];

        // W1+W2 (ADR-058) — Drone-recon. Точка входа в крафт первого DroneScout
        // (без неё фича была BUILT-BUT-DEAD: recipe был, кнопки не было).
        // Показываем только при включённом killswitch — иначе категория пустая.
        if ((new \App\Services\Player\DroneService())->isEnabled()) {
            $rows[] = [
                ['text' => '🚁 Дрон-разведчик', 'callback_data' => 'droneScout'],
            ];
        }

        $rows[]    = [
            ['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue'],
        ];
        $keyboard = ['inline_keyboard' => $rows];

        $imagePath = base_url('uploads/telegram/craft/standard/standard_craft_area.jpg'); // Укажите актуальный путь к изображению

        // Ответ на callback-запрос, чтобы убрать "часики" на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
