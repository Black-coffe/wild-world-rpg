<?php

namespace app\TaskHandlers\Built;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class BuiltCompletionGreenhouseHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    private $telegram;

    // Конструктор без изменений
    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterFactionModel  = new CharacterFactionModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->telegramUserModel      = new TelegramUserModel();

        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Теперь явно прописываем возвращаемый тип ServerResponse
     * или, если не уверены, то ?ServerResponse (разрешая null).
     * Но чаще всего нужно именно ServerResponse, ведь notifyUser возвращает его.
     */
    public function handle($task): ServerResponse
    {
        // 1) Закрытие задачи
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Получение информации о строительном сооружении
        $buildingBlock = $this->buildingModel->where('name_en', 'Greenhouse')->first();

        if (!$buildingBlock) {
            log_message('error', 'Созданный элемент: Greenhouse, не найден в базе данных');
            // Здесь тоже надо что-то вернуть типа ServerResponse:
            return Request::sendMessage([
                'chat_id' => 'YOUR_ADMIN_CHAT_ID', // либо пустая заглушка
                'text'    => 'Ошибка: Greenhouse не найден в БД',
            ]);
            // Или, как вариант, Request::emptyResponse()
            // return Request::emptyResponse();
        }

        // 3) Обновление/создание лога крафта
        $this->updateCharacterBuildings($task, $buildingBlock);

        // 4) Обновление атрибутов
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.08, // ловкость
            0.07  // интеллект
        );

        // 5) Отправка уведомления в Telegram
        // notifyUser как раз возвращает ServerResponse
        return $this->notifyUser($task['telegram_user_id']);
    }

    private function updateCharacterBuildings($task, $buildingBlock): void
    {
        // Код без изменений (void — ведь ничего не возвращаем)
        $existingBuilding = $this->characterBuildingModel->where([
            'character_id' => $task['character_id'],
            'building_id'  => $buildingBlock['id']
        ])->first();

        if ($existingBuilding) {
            $data = ['amount' => $existingBuilding['amount'] + 1];
            $this->characterBuildingModel->update($existingBuilding['id'], $data);
        } else {
            $characterFaction = $this->characterFactionModel
                ->where('character_id', $task['character_id'])
                ->first();
            $characterFactionId = $characterFaction ? $characterFaction['faction_id'] : null;

            $character = $this->characterModel->find($task['character_id']);
            if (!$character) {
                log_message('error', 'Character not found for task ID: ' . $task['id']);
                return;
            }

            $data = [
                'character_id'  => $task['character_id'],
                'building_id'   => $buildingBlock['id'],
                'faction_id'    => $characterFactionId,
                'map_cell_id'   => $character['cell_number'],
                'amount'        => 1,
                'character_level_during_construction' => $character['level'],
                'hp'            => $buildingBlock['hp'],
                'level'         => 1,
                'built_at'      => date('Y-m-d H:i:s'),
                'building_type' => 'farming',
                'tax'           => $buildingBlock['tax'],
                'usage'         => $buildingBlock['usage'],
            ];
            $this->characterBuildingModel->insert($data);
        }
    }

    /**
     * notifyUser тоже возвращает ServerResponse.
     */
    private function notifyUser($telegramUserId): ServerResponse
    {
        // 1) Проверяем пользователя
        $telegramUser = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramUser) {
            log_message('error', 'Telegram user not found for ID: ' . $telegramUserId);
            return Request::sendMessage([
                'chat_id' => $telegramUserId,
                'text'    => "Ошибка: пользователь не найден.",
            ]);
        }

        $telegram_id = $telegramUser['telegram_id'];

        // 2) Формируем текст
        $text = "📌 Вы успешно построили:\n\n"
            . "*🌱 Теплицу*\n\n"
            . "Зона применения: *База* 🏚️";

        $imagePath = base_url('uploads/telegram/camp/Greenhouse_craft.png');

        // 3) Отвечаем на callback (по ID)
        Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);

        // 4) Пытаемся отправить фото
        try {
            return Request::sendPhoto([
                'chat_id'    => $telegram_id,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Telegram API error: " . $e->getMessage());
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}

