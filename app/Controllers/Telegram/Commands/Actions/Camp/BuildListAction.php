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
            [
                // Новое здание: Центр телепортации
                'name' => "🌀 Центр телепортации",
                'tax' => 1200,
                'callback_data' => 'buildTeleportationCenter'
            ],
        ];

        $buildingList = "";
        $keyboardButtons = [];

        // Формируем список и кнопки
        foreach ($buildingsInfo as $b) {
            $buildingList .= "*{$b['name']}* | *Налог: {$b['tax']}* 💰\n\n";
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
            . "_Выбери желаемое здание для строительства._";

        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
