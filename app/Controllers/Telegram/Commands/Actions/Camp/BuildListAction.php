<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

class BuildListAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // 1) answerCallbackQuery — убираем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ошибка: нет пользователя/персонажа.'
            ]);
        }

        // 3) Проверяем переезд (если есть активная задача переезда, запрещаем строительство)
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            // true = переезд активен, сервис уже отправил сообщение об этом, ничего не делаем дальше
            return Request::emptyResponse();
        }

        // Список построек с иконками, названием и суточным налогом
        $buildingsInfo = [
            [
                'name' => "🚰 Ручная скважина",
                'tax' => 300,
                'callback_data' => 'buildHandPump'
            ],
            [
                'name' => "🔥 Доменная печь",
                'tax' => 450,
                'callback_data' => 'buildBlastFurnace'
            ],
            [
                'name' => "🏚️ Склад",
                'tax' => 900,
                'callback_data' => 'buildWarehouse'
            ],
            [
                'name' => "🔧 Мастерская",
                'tax' => 500,
                'callback_data' => 'buildWorkshop'
            ],
            [
                'name' => "🌱 Теплица",
                'tax' => 840,
                'callback_data' => 'buildGreenhouse'
            ],
            [
                'name' => "☀️ Солнечная станция",
                'tax' => 760,
                'callback_data' => 'buildSolarStation'
            ],
            [
                'name' => "🥊 Спортзал",
                'tax' => 900,
                'callback_data' => 'buildGym'
            ],
            [
                'name' => "🥼 Лаборатория",
                'tax' => 860,
                'callback_data' => 'buildLaboratory'
            ],
            [
                'name' => "🤖 Мастерская робототехники",
                'tax' => 1400,
                'callback_data' => 'buildRoboticsWorkshop'
            ],
            [
                'name' => "🌀 Центр телепортации",
                'tax' => 820,
                'callback_data' => 'buildTeleportationCenter'
            ],
            [
                'name' => "⚔️ Арсенал",
                'tax'  => 2000,
                'callback_data' => 'actionNameForArsenal'
            ],
            [
                'name' => "📢 Вышка связи",
                'tax'  => 1300,
                'callback_data' => 'actionNameForCommunicationTower'
            ],
        ];

        $buildingList = "";
        $keyboardButtons = [];

        // Формируем список и кнопки
        foreach ($buildingsInfo as $b) {
            $buildingList .= "*{$b['name']}* | *Налог: {$b['tax']}* 💰\n";
            $keyboardButtons[] = [
                'text' => $b['name'],
                'callback_data' => $b['callback_data']
            ];
        }

        // Разбиваем на ряды по 2 кнопки
        $keyboard = array_chunk($keyboardButtons, 2);

        $text = "🤖 Это я – *Роби*!\n\n"
            . "Вот список доступных построек с указанием суточного налога:\n\n"
            . "{$buildingList}"
            . "\n_Выбери желаемое здание для строительства._";

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
