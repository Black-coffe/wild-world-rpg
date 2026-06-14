<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;

class RoboticsWorkshopHandler extends BaseAction
{
    protected $characterBuildingModel;
    protected $buildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel = new BuildingModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // Получаем информацию о постройке
        // E28: динамический ID по name_en (см. BuildingModel::idByNameEn, NAVIGATION_MAP #25)
        $buildingId = $this->buildingModel->idByNameEn('RoboticsWorkshop');
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет данной постройки.',
            ]);
        }

        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Информация о постройке не найдена.',
            ]);
        }

        $imagePath = base_url('uploads/telegram/camp/Robotics-Workshop.jpg');

        $text = sprintf(
            "🏗️ *%s*\n\n📅 *Дата постройки:* %s\n⏳ *Дата исчезновения:* %s\n🔄 *Использований:* %d всего / %s осталось\n💰 *Налог за постройку:* %d$\n🆙 *Уровень постройки:* %d lvl\n🔒 *Доступность:* %s\n📜 *Описание:* %s",
            $buildingInfo['name_ru'],
            date('d.m.Y', strtotime($characterBuilding['built_at'])),
            $characterBuilding['disappearance_date'] ? date('d.m.Y', strtotime($characterBuilding['disappearance_date'])) : 'Без ограничений',
            $characterBuilding['usage_count'] ?? 0,
            $characterBuilding['usage_count'] !== null ? ($buildingInfo['usage_count'] - $characterBuilding['usage_count']) : 'Без ограничений',
            $characterBuilding['tax'],
            $characterBuilding['level'],
            $characterBuilding['usage'] === 'personal' ? 'персональная' : 'общая',
            $buildingInfo['description']
        );

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
                ],
                [
                    ['text' => '🆙 Поднять уровень', 'callback_data' => 'upgrade_building_' . $buildingId],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
