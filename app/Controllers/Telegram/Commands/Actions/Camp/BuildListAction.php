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

        // S1 (v0.51.182+): callback_data → единый `genericBuildInfo_<Key>` (читает Config\Buildings).
        // Раньше каждая кнопка имела свой legacy callback (buildHandPump/...). Удалены.
        $buildingsInfo = [
            ['name' => "🚰 Ручная скважина",            'tax' => 300,  'callback_data' => 'genericBuildInfo_HandPump'],
            ['name' => "🔥 Доменная печь",              'tax' => 450,  'callback_data' => 'genericBuildInfo_BlastFurnace'],
            ['name' => "🏚️ Склад",                       'tax' => 900,  'callback_data' => 'genericBuildInfo_Warehouse'],
            ['name' => "🔧 Мастерская",                  'tax' => 500,  'callback_data' => 'genericBuildInfo_Workshop'],
            ['name' => "🌱 Теплица",                     'tax' => 840,  'callback_data' => 'genericBuildInfo_Greenhouse'],
            ['name' => "☀️ Солнечная станция",          'tax' => 760,  'callback_data' => 'genericBuildInfo_SolarStation'],
            ['name' => "🥊 Спортзал",                    'tax' => 900,  'callback_data' => 'genericBuildInfo_Gym'],
            ['name' => "🥼 Лаборатория",                 'tax' => 860,  'callback_data' => 'genericBuildInfo_Laboratory'],
            ['name' => "🤖 Мастерская робототехники",   'tax' => 1400, 'callback_data' => 'genericBuildInfo_RoboticsWorkshop'],
            ['name' => "🌀 Центр телепортации",          'tax' => 820,  'callback_data' => 'genericBuildInfo_TeleportationCenter'],
            ['name' => "⚔️ Арсенал",                    'tax' => 2000, 'callback_data' => 'genericBuildInfo_Arsenal'],
            ['name' => "📢 Вышка связи",                 'tax' => 1300, 'callback_data' => 'genericBuildInfo_CommunicationTower'],
            // S26 (v0.51.207, ADR-030) — defensive structures (защита базы в PvP).
            ['name' => "🪵 Деревянная стена",            'tax' => 200,  'callback_data' => 'genericBuildInfo_WoodenWall'],
            ['name' => "🌵 Колючая ограда",              'tax' => 350,  'callback_data' => 'genericBuildInfo_BarbedFence'],
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

        // #12 edit-in-place (ADR-018): меню строительства — навигация → редактируем текущее
        // сообщение (даём ему фото, чтобы editMessageMedia работал и из/в photo-меню базы).
        $imagePath = base_url('uploads/telegram/camp/Construction-by-improvised.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
