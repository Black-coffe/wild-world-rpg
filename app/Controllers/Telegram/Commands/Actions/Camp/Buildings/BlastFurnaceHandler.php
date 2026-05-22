<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;

class BlastFurnaceHandler extends BaseAction
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

        // Допустим, в таблице `buildings` у Доменной печи (BlastFurnace) — id=2
        $buildingId = 2;  // ID для BlastFurnace

        // Ищем, построил ли пользователь такую постройку
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        // Если у пользователя нет доменной печи
        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет Доменной печи на базе.',
            ]);
        }

        // Находим инфо о здании
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Информация о Доменной печи не найдена.',
            ]);
        }

        // Здесь указываете путь к картинке
        $imagePath = base_url('uploads/telegram/camp/blast_furnace.png');

        // Формируем текст, аналогично HandPumpHandler
        $text = sprintf(
            "🏗️ *%s*\n\n".
            "📅 *Дата постройки:* %s\n".
            "⏳ *Дата исчезновения:* %s\n".
            "🔄 *Использований:* %d всего / %s осталось\n".
            "💰 *Налог за постройку:* %d$\n".
            "🆙 *Уровень постройки:* %d lvl\n".
            "🔒 *Доступность:* %s\n".
            "📜 *Описание:* %s",
            $buildingInfo['name_ru'],
            date('d.m.Y', strtotime($characterBuilding['built_at'])),
            $characterBuilding['disappearance_date']
                ? date('d.m.Y', strtotime($characterBuilding['disappearance_date']))
                : 'Без ограничений',
            // Текущий usage_count
            $characterBuilding['usage_count'] ?? 0,
            // Сколько осталось (если у buildingInfo вообще есть параметр usage_count)
            $buildingInfo['usage_count'] && $characterBuilding['usage_count'] !== null
                ? ($buildingInfo['usage_count'] - $characterBuilding['usage_count'])
                : 'Без ограничений',
            $characterBuilding['tax'],
            $characterBuilding['level'],
            // Например, если usage = 'personal' => "персональная", если 'collective' => "коллективная" и т. д.
            $characterBuilding['usage'] === 'personal' ? 'персональная' : 'общая',
            $buildingInfo['description']
        );

        // Формируем кнопки, аналогично
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🆙 Поднять уровень', 'callback_data' => 'upgrade_building_' . $buildingId],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];

        // Закрываем alert
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
