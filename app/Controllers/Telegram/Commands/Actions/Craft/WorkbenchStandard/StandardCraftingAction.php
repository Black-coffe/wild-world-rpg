<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class StandardCraftingAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $scope = new \App\Services\Tasks\ActionScopeService();
        $text = "*Ты в разделе 🔧 Стандартный крафт* 🏭\n\n"
            . "Здесь крафтятся *продвинутые вещи*: роботы, телепорты, броня, оружие и дроны.\n"
            . "Нужны *🔬 Верстак 1* и построенная база — но стоять на ней не нужно, запускать можно откуда угодно.\n\n"
            . $scope->legend(\App\Services\Tasks\ActionScopeService::KIND_CRAFT) . "\n\n"
            . "_Выбирай направление крафта — если хватит ресурсов, получишь нужную вещь_ 👇\n";

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

        // W1+W2 (ADR-058) + W3b (ADR-060) + W4 (ADR-063) + W5 (ADR-064) — Drones.
        // Точки входа в крафт DroneScout / DroneCargo / DroneRepair / DroneCombat
        // (без них фичи становятся BUILT-BUT-DEAD: recipe есть, кнопки нет). Кнопки
        // sibling-класса пакуем по 2 в строку (memory feedback_inline_keyboard_pack_sibling_buttons).
        $droneService = new \App\Services\Player\DroneService();
        $droneRow = [];
        if ($droneService->isEnabled()) {
            $droneRow[] = ['text' => '🚁 Дрон-разведчик', 'callback_data' => 'droneScout'];
        }
        if ($droneService->cargoIsEnabled()) {
            $droneRow[] = ['text' => '🚚 Карго-дрон', 'callback_data' => 'droneCargo'];
        }
        if ($droneService->repairIsEnabled()) {
            $droneRow[] = ['text' => '🔧 Дрон-ремонтник', 'callback_data' => 'droneRepair'];
        }
        if ($droneService->combatIsEnabled()) {
            $droneRow[] = ['text' => '🛡 Боевой дрон', 'callback_data' => 'droneCombat'];
        }
        if (! empty($droneRow)) {
            // 3+ кнопок — паковать по 2 в строку.
            if (count($droneRow) >= 3) {
                for ($i = 0, $n = count($droneRow); $i < $n; $i += 2) {
                    $rows[] = array_slice($droneRow, $i, 2);
                }
            } else {
                $rows[] = $droneRow;
            }
        }

        $rows[]    = [
            ['text' => '📋 Очередь крафта',  'callback_data' => 'craftQueue'],
            ['text' => '🔬 Верстаки',         'callback_data' => 'WorkbenchChoice'],
        ];
        $rows[]    = [
            ['text' => '🔨 К общему крафту', 'callback_data' => 'generalCraft'],
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
