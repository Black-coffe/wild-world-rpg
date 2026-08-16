<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;

class WorkshopHandler extends BaseAction
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
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
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

        // E28: динамический ID по name_en (см. BuildingModel::idByNameEn, NAVIGATION_MAP #25)
        $buildingId = $this->buildingModel->idByNameEn('Workshop');

        // Проверяем, построил ли персонаж Мастерскую
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет Мастерской на базе.',
            ]);
        }

        // Получаем информацию о самом здании
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о Мастерской не найдена.',
            ]);
        }

        // Путь к изображению Мастерской (обязательно укажите корректный путь на сервере)
        $imagePath = base_url('uploads/telegram/camp/WorkShop.png');

        // Формируем текстовое описание
        $text = sprintf(
            "🏗️ *%s*\n\n" .
            "📅 *Дата постройки:* %s\n" .
            "⏳ *Дата исчезновения:* %s\n" .
            "🔄 *Использований:* %d всего / %s осталось\n" .
            "💰 *Налог за постройку:* %d$\n" .
            "🆙 *Уровень постройки:* %d lvl\n" .
            "🔒 *Доступность:* %s\n" .
            "📜 *Описание:* %s",
            $buildingInfo['name_ru'],
            date('d.m.Y', strtotime($characterBuilding['built_at'])),
            $characterBuilding['disappearance_date']
                ? date('d.m.Y', strtotime($characterBuilding['disappearance_date']))
                : 'Без ограничений',
            // Сколько раз уже использовали
            $characterBuilding['usage_count'] ?? 0,
            // Сколько осталось (если есть usage_count в buildingInfo и оно не null в characterBuilding)
            ($buildingInfo['usage_count'] !== null && $characterBuilding['usage_count'] !== null)
                ? max(0, $buildingInfo['usage_count'] - $characterBuilding['usage_count'])
                : 'Без ограничений',
            $characterBuilding['tax'],
            $characterBuilding['level'],
            $characterBuilding['usage'] === 'personal'
                ? 'персональная'
                : ($characterBuilding['usage'] === 'collective' ? 'коллективная' : 'общая'),
            $buildingInfo['description']
        );

        // Клавиатура с кнопками
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🆙 Поднять уровень', 'callback_data' => 'upgrade_building_' . $buildingId],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];

        // Закрываем всплывающее уведомление
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем фотографию с описанием постройки
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
