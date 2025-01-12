<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class BuildListAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        // Список построек с информацией
        $buildingsInfo = [
            [
                'name' => "🚰 Ручная скважина",
                'tax' => 24,
                'callback_data' => 'buildHandPump'
            ],
            [
                'name' => "🔥 Доменная печь",
                'tax' => 22,
                'callback_data' => 'buildBlastFurnace'
            ],
            [
                'name' => "🏚️ Склад",
                'tax' => 100,
                'callback_data' => 'buildWarehouse'
            ],
            [
                'name' => "🔧 Мастерская",
                'tax' => 96,
                'callback_data' => 'buildWorkshop'
            ],
            [
                'name' => "🌱 Теплица",
                'tax' => 80,
                'callback_data' => 'buildGreenhouse'
            ],
            [
                'name' => "☀️ Солнечная станция",
                'tax' => 62,
                'callback_data' => 'buildSolarStation'
            ],
            [
                'name' => "🥊 Спортзал",
                'tax' => 54,
                'callback_data' => 'buildGym'
            ],
            [
                'name' => "🥼 Лаборатория",
                'tax' => 200,
                'callback_data' => 'buildLaboratory'
            ],
            [
                'name' => "🤖 Мастерская робототехники",
                'tax' => 140,
                'callback_data' => 'buildRoboticsWorkshop'
            ],
        ];

        $buildingList = "";
        $keyboardButtons = [];

        foreach ($buildingsInfo as $building) {
            $buildingList .= "*{$building['name']}* | "
                . "*Налог: {$building['tax']}* 💰 \n\n";

            $keyboardButtons[] = [
                'text' => "{$building['name']}",
                'callback_data' => $building['callback_data']
            ];
        }

        $keyboard = array_chunk($keyboardButtons, 2);

        $text = "🤖 Это я – *Роби*!\n\n"
            . "Вот список доступных построек с стоимостью налога за сооружение в сутки:\n\n"
            . "{$buildingList}";

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
