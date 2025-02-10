<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\Tasks\ActiveTasksService;

/**
 * Class CommunicationTowerHandler
 * Аналог ArsenalHandler, но для "Вышки связи" (CommunicationTower).
 */
class CommunicationTowerHandler extends BaseAction
{
    protected $characterBuildingModel;
    protected $buildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: не найден пользователь или персонаж.'
            ]);
        }

        // 1) Проверка переезда
        $relocationBlocked = (new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        );
        if ($relocationBlocked) {
            return Request::emptyResponse();
        }

        // 2) Находим здание CommunicationTower
        $towerRow = $this->buildingModel
            ->where('name_en', 'CommunicationTower')
            ->first();
        if (!$towerRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Здание "Вышка связи" не найдено в таблице buildings.'
            ]);
        }
        $buildingId = $towerRow['id'];

        // 3) Проверяем, есть ли у персонажа запись в character_buildings
        $charBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        if (!$charBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет здания "Вышка связи" на базе.'
            ]);
        }

        // 4) Сформируем описание
        $imagePath = base_url('uploads/telegram/camp/communication_tower.png');

        $text = sprintf(
            "📡 *%s*\n\n" .
            "📅 *Дата постройки:* %s\n" .
            "⏳ *Дата исчезновения:* %s\n" .
            "🔄 *Использований:* %d всего / %s осталось\n" .
            "💰 *Налог:* %d$\n" .
            "🆙 *Уровень:* %d lvl\n" .
            "🔒 *Доступность:* %s\n" .
            "📜 *Описание:* %s\n\n" .
            "_Каждый уровень увеличивает радиус связи +100 клеток._",
            $towerRow['name_ru'], // "Вышка связи"
            date('d.m.Y', strtotime($charBuilding['built_at'])),
            $charBuilding['disappearance_date']
                ? date('d.m.Y', strtotime($charBuilding['disappearance_date']))
                : 'Без ограничений',
            $charBuilding['usage_count'] ?? 0,
            $towerRow['usage_count'] && $charBuilding['usage_count'] !== null
                ? ($towerRow['usage_count'] - $charBuilding['usage_count'])
                : 'Без ограничений',
            $charBuilding['tax'],
            $charBuilding['level'],
            $charBuilding['usage'] === 'personal' ? 'персональная' : 'общая',
            $towerRow['description']
        );

        // 5) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🆙 Поднять уровень',   'callback_data' => 'upgrade_building_' . $buildingId],
                    ['text' => '🔄 Обновить постройку', 'callback_data' => 'renew_building_'   . $buildingId],
                ],
                [
                    ['text' => '❌ Удалить строение', 'callback_data' => 'delete_building_' . $buildingId],
                    ['text' => '🏠 База',            'callback_data' => 'Base'],
                ],
            ],
        ];

        // Снимаем "часики"
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
