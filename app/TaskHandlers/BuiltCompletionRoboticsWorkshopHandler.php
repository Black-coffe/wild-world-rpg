<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use CodeIgniter\Controller;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

class BuiltCompletionRoboticsWorkshopHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
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
        $buildingBlock = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first();

        if (!$buildingBlock) {
            log_message('error', 'Созданный элемент: RoboticsWorkshop, не найден в базе данных');
            return;
        }

        // Обновление или создание лога крафта
        $this->updateCharacterBuildings($task, $buildingBlock);

        // Обновление атрибутов персонажа после крафта
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            2.6, // увеличение ловкости
            2.8  // увеличение интеллекта
        );

        // Отправка уведомления в Telegram
        $this->notifyUser($task['telegram_user_id']);
    }

    private function updateCharacterBuildings($task, $buildingBlock)
    {
        $existingBuilding = $this->characterBuildingModel->where([
            'character_id' => $task['character_id'],
            'building_id' => $buildingBlock['id']
        ])->first();

        if ($existingBuilding) {
            $data = [
                'amount' => $existingBuilding['amount'] + 1
            ];
            $this->characterBuildingModel->update($existingBuilding['id'], $data);
        } else {
            // Проверка наличия фракции и установка NULL, если фракции нет
            $characterFaction = $this->characterFactionModel->where('character_id', $task['character_id'])->first();
            $characterFactionId = $characterFaction ? $characterFaction['faction_id'] : null;

            // Получаем информацию о персонаже
            $character = $this->characterModel->where('id', $task['character_id'])->first();
            if (!$character) {
                log_message('error', 'Character not found for task ID: ' . $task['id']);
                return;
            }

            // Подготовка данных для вставки нового здания
            $data = [
                'character_id' => $task['character_id'],
                'building_id' => $buildingBlock['id'],
                'faction_id' => $characterFactionId, // Устанавливаем фракцию или NULL
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
            $this->characterBuildingModel->insert($data);
        }
    }

    private function notifyUser($telegramUserId): \Longman\TelegramBot\Entities\ServerResponse
    {
        // Проверка наличия пользователя Telegram
        $telegramUser = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramUser) {
            log_message('error', 'Telegram user not found for user ID: ' . $telegramUserId);
            return Request::sendMessage([
                'chat_id' => $telegramUserId,
                'text' => "Ошибка: пользователь не найден.",
            ]);
        }

        $telegram_id = $telegramUser['telegram_id'];

        $text = "📌 Вы успешно построили:\n\n"
            . "*🤖 Мастерскую робототехники*\n\n"
            . "Зона применения: *База* 🏚️";

        $imagePath = base_url('uploads/telegram/camp/Robotics-Workshop.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);
        try {
            return Request::sendPhoto([
                'chat_id' => $telegram_id,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text' => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
