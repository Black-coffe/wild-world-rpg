<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\Tasks\ActiveTasksService;

/**
 * Class ArsenalHandler
 * Класс, обрабатывающий отображение информации о здании "Арсенал" на базе игрока.
 */
class ArsenalHandler extends BaseAction
{
    protected $characterBuildingModel;
    protected $buildingModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel         = new BuildingModel();
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

        // 1. Проверка активного "переезда" (BaseRelocation)
        $relocationBlocked = (new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        );
        if ($relocationBlocked) {
            // Сервис уже отправил сообщение о блокировке.
            return Request::emptyResponse();
        }

        // 2. Пытаемся получить запись "Arsenal" из таблицы buildings.
        //    Заменяем жёсткое `$buildingId = 15` на динамический поиск:
        $arsenalRow = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenalRow) {
            // Если вдруг записи нет, выводим ошибку
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Здание "Арсенал" не найдено в таблице `buildings`.',
            ]);
        }

        $buildingId = $arsenalRow['id'];  // ID здания «Арсенал»

        // 3. Ищем запись character_buildings для "Арсенала"
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        // Если у игрока нет Арсенала
        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет здания "Арсенал" на базе.',
            ]);
        }

        // 4. Находим дополнительную информацию о здании (arsenalRow уже содержит всё нужное)
        // Можно либо повторно использовать $arsenalRow, либо сделать find($buildingId).
        // Здесь покажем вариант использования $arsenalRow напрямую:
        $buildingInfo = $arsenalRow;

        // 5. Путь к изображению
        $imagePath = base_url('uploads/telegram/camp/arsenal.png');
        // Убедитесь, что путь и файл существуют.

        // 6. Формируем описание (примерно как TeleportationCenterHandler)
        $text = sprintf(
            "🏗️ *%s*\n\n" .
            "📅 *Дата постройки:* %s\n" .
            "⏳ *Дата исчезновения:* %s\n" .
            "🔄 *Использований:* %d всего / %s осталось\n" .
            "💰 *Налог за постройку:* %d$\n" .
            "🆙 *Уровень постройки:* %d lvl\n" .
            "🔒 *Доступность:* %s\n" .
            "📜 *Описание:* %s",
            $buildingInfo['name_ru'], // "Арсенал"
            date('d.m.Y', strtotime($characterBuilding['built_at'])),
            $characterBuilding['disappearance_date']
                ? date('d.m.Y', strtotime($characterBuilding['disappearance_date']))
                : 'Без ограничений',

            // Текущий usage_count
            $characterBuilding['usage_count'] ?? 0,
            // Сколько осталось (если в самом buildingInfo['usage_count'] есть число)
            $buildingInfo['usage_count'] && $characterBuilding['usage_count'] !== null
                ? ($buildingInfo['usage_count'] - $characterBuilding['usage_count'])
                : 'Без ограничений',

            $characterBuilding['tax'],
            $characterBuilding['level'],

            // Например, если usage='personal' => "персональная", если 'all' => "общая"
            $characterBuilding['usage'] === 'personal' ? 'персональная' : 'общая',

            $buildingInfo['description']
        );

        // 7. Кнопки (Upgrade, Renew, Delete, Base)
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

        // 8. Закрываем «часики» callback
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // 9. Отправляем фото + описание
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
