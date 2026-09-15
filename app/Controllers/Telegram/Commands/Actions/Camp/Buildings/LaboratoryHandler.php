<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;

class LaboratoryHandler extends BaseAction
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
        $buildingId = $this->buildingModel->idByNameEn('Laboratory');

        // ADR-095 мульти-база: читаем строку character_buildings ТОЙ базы, где стоит
        // игрок — иначе карточка путает уровень с другой базы персонажа (angela-second-base-bugs #2).
        $currentCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        // story multibase-picker-03: суффикс `_b<baseId>` (если есть) — заново
        // проверенный выбор базы; без суффикса работает прежнее правило.
        [, $baseId] = \App\Services\Bases\BaseCallbackSuffix::split($this->callbackQuery->getData());
        $scope = $baseId !== null
            ? (new \App\Services\Bases\BaseScopeResolver())->resolveForBase((int) $character['id'], $currentCell, $baseId)
            : (new \App\Services\Bases\BaseScopeResolver())->resolve((int) $character['id'], $currentCell);
        $targetCell = $scope['cell'];
        if ($targetCell === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $scope['text'],
            ]);
        }

        // Проверяем, есть ли у персонажа постройка "Лаборатория"
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->where('map_cell_id', $targetCell)
            ->first();

        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет Лаборатории на базе.',
            ]);
        }

        // Получаем информацию о Лаборатории
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о Лаборатории не найдена.',
            ]);
        }

        // Путь к изображению Лаборатории (проверьте правильность пути у себя в проекте)
        $imagePath = base_url('uploads/telegram/camp/laboratory.jpg');

        // Формируем описание, аналогично другим классам
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
            // Сколько раз уже использовалась постройка
            $characterBuilding['usage_count'] ?? 0,
            // Сколько осталось использований (если в buildingInfo вообще указан usage_count)
            ($buildingInfo['usage_count'] !== null && $characterBuilding['usage_count'] !== null)
                ? max(0, $buildingInfo['usage_count'] - $characterBuilding['usage_count'])
                : 'Без ограничений',
            $characterBuilding['tax'],
            $characterBuilding['level'],
            // Определяем, как перевести 'usage'
            $characterBuilding['usage'] === 'personal'
                ? 'персональная'
                : ($characterBuilding['usage'] === 'collective' ? 'коллективная' : 'общая'),
            $buildingInfo['description']
        );

        // Формируем клавиатуру
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🆙 Поднять уровень', 'callback_data' => $baseId !== null
                        ? \App\Services\Bases\BaseCallbackSuffix::append('upgrade_building_' . $buildingId, $baseId)
                        : 'upgrade_building_' . $buildingId],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];

        // Закрываем alert над кнопкой
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем фото с описанием
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
