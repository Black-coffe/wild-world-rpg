<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;

class GreenhouseHandler extends BaseAction
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
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Предположим, что в таблице `buildings` у Теплицы (Greenhouse) id=5
        $buildingId = 5;

        // Проверяем, есть ли у игрока постройка "Теплица"
        $characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $buildingId)
            ->first();

        if (!$characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет Теплицы на базе.',
            ]);
        }

        // Находим информацию о здании "Теплица"
        $buildingInfo = $this->buildingModel->find($buildingId);
        if (!$buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о Теплице не найдена.',
            ]);
        }

        // Предполагаемый путь к изображению Теплицы
        $imagePath = base_url('uploads/telegram/camp/Greenhouse_craft.png');

        // Формируем текст для вывода
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
            // Текущее количество использований (либо 0, если null)
            $characterBuilding['usage_count'] ?? 0,
            // Сколько осталось (если у buildingInfo стоит usage_count, отнимаем то, что уже использовано)
            ($buildingInfo['usage_count'] !== null && $characterBuilding['usage_count'] !== null)
                ? max(0, $buildingInfo['usage_count'] - $characterBuilding['usage_count'])
                : 'Без ограничений',
            $characterBuilding['tax'],
            $characterBuilding['level'],
            // Если usage = 'personal' => персональная, 'collective' => коллективная, иначе — общая
            $characterBuilding['usage'] === 'personal'
                ? 'персональная'
                : ($characterBuilding['usage'] === 'collective' ? 'коллективная' : 'общая'),
            $buildingInfo['description']
        );

        // Формируем клавиатуру с кнопками
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🆙 Поднять уровень', 'callback_data' => 'upgrade_building_' . $buildingId],
                    ['text' => '🔄 Обновить постройку', 'callback_data' => 'renew_building_' . $buildingId],
                ],
                [
                    ['text' => '❌ Удалить строение', 'callback_data' => 'delete_building_' . $buildingId],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ],
        ];

        // Закрываем "alert" нажатия кнопки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем фото с описанием
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
