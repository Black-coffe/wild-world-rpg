<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel; // Подключение модели персонажа для обновления атрибутов
use App\Models\CharacterTaskModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use CodeIgniter\Controller;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

class BuiltCompletionHandPumpHandler extends Controller
{
    protected $characterModel; // Добавление свойства модели персонажа
    protected $characterTaskModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel(); // Инициализация модели персонажа
        $this->buildingModel = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function handle($task)
    {
        // Закрытие задачи
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Получение информации о строительном сооружении
        $buildingBlock = $this->buildingModel->where('name_en', 'HandPump')->first();

        if (!$buildingBlock) {
            log_message('error', 'Созданный элемент: HandPump, не найден в базе данных');
            return;
        }

        // Обновление или создание лога крафта
        $this->updateCharacterBuildings($task, $buildingBlock);

        // Обновление атрибутов персонажа после крафта
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            1, // увеличение ловкости
            1.2  // увеличение интеллекта
        );

        // Отправка уведомления в Telegram
        $this->notifyUser($task['telegram_user_id'], $buildingBlock, $task['character_id']);
    }

    private function updateCharacterBuildings($task, $buildingBlock)
    {
        // Проверяем наличие существующего здания
        $existingBuilding = $this->characterBuildingModel->where([
            'character_id' => $task['character_id'],
            'building_id' => $buildingBlock['id']
        ])->first();

        if ($existingBuilding) {
            // Если здание уже существует, обновляем количество
            $data = [
                'amount' => $existingBuilding['amount'] + 1
            ];
            log_message('debug', 'Updating existing building: ' . print_r($data, true));
            $this->characterBuildingModel->update($existingBuilding['id'], $data);
            if ($this->characterBuildingModel->errors()) {
                log_message('error', 'Update errors: ' . print_r($this->characterBuildingModel->errors(), true));
            }
        } else {
            // Если здание новое, создаём его
            $characterFaction = $this->characterFactionModel->where('character_id', $task['character_id'])->first();
            $characterFactionId = $characterFaction ? $characterFaction['faction_id'] : null; // Если фракция отсутствует, записываем NULL

            // Получаем информацию о персонаже
            $character = $this->characterModel->where('id', $task['character_id'])->first();
            if (!$character) {
                log_message('error', 'Character not found for task ID: ' . $task['id']);
                return;
            }

            // Подготовка данных для вставки в базу
            $data = [
                'character_id' => $task['character_id'],
                'building_id' => $buildingBlock['id'],
                'faction_id' => $characterFactionId, // Устанавливаем NULL, если фракция не найдена
                'map_cell_id' => $character['cell_number'],
                'amount' => 1,
                'character_level_during_construction' => $character['level'],
                'hp' => $buildingBlock['hp'],
                'level' => 1,
                'built_at' => date('Y-m-d H:i:s'),
                'building_type' => 'farming',
                'tax' => $buildingBlock['tax'],
                'usage' => $buildingBlock['usage'],
            ];
            log_message('debug', 'Inserting new building: ' . print_r($data, true));
            $this->characterBuildingModel->insert($data);
            if ($this->characterBuildingModel->errors()) {
                log_message('error', 'Insert errors: ' . print_r($this->characterBuildingModel->errors(), true));
            }
        }
    }

    private function notifyUser($telegramUserId, $buildingBlock, $characterId): \Longman\TelegramBot\Entities\ServerResponse
    {
        $telegramUser = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramUser) {
            log_message('error', 'Telegram user not found for user ID: ' . $telegramUserId);
            // Возвращаем "пустой" объект ответа с сообщением об ошибке
            return Request::sendMessage([
                'chat_id' => $telegramUserId,
                'text' => "Ошибка: пользователь не найден.",
            ]);
        }

        $telegram_id = $telegramUser['telegram_id'];
        $text = "📌 Вы успешно построили:\n\n"
            . "🚰 *Ручную скважину!*\n\n"
            . "Зона применения: *База* 🏚️";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/camp/hand_pump.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);
        try {
            return Request::sendPhoto([
                'chat_id' => $telegram_id,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            // Возвращаем сообщение с ошибкой через Telegram API
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text' => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }

}
