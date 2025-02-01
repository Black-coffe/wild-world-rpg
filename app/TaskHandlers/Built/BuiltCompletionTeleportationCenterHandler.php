<?php

namespace app\TaskHandlers\Built;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class BuiltCompletionTeleportationCenterHandler extends Controller
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
            // Обработка исключения по необходимости
        }
    }

    /**
     * Завершает задачу строительства центра телепортации.
     *
     * @param array $task Массив данных задачи строительства.
     */
    public function handle($task)
    {
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        $buildingBlock = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$buildingBlock) {
            return;
        }

        $this->updateCharacterBuildings($task, $buildingBlock);

        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            1.5,
            1.5
        );

        $this->notifyUser($task['telegram_user_id']);
    }

    /**
     * Обновляет или создаёт запись о здании в логах персонажа.
     *
     * @param array $task Данные задачи строительства.
     * @param array $buildingBlock Данные здания TeleportationCenter.
     */
    private function updateCharacterBuildings($task, $buildingBlock)
    {
        $existingBuilding = $this->characterBuildingModel->where([
            'character_id' => $task['character_id'],
            'building_id'  => $buildingBlock['id']
        ])->first();

        if ($existingBuilding) {
            $data = [
                'amount' => $existingBuilding['amount'] + 1
            ];
            $this->characterBuildingModel->update($existingBuilding['id'], $data);
        } else {
            $characterFaction   = $this->characterFactionModel->where('character_id', $task['character_id'])->first();
            $characterFactionId = $characterFaction ? $characterFaction['faction_id'] : null;
            $character          = $this->characterModel->where('id', $task['character_id'])->first();
            if (!$character) {
                return;
            }
            $data = [
                'character_id'                      => $task['character_id'],
                'building_id'                       => $buildingBlock['id'],
                'faction_id'                        => $characterFactionId,
                'map_cell_id'                       => $character['cell_number'],
                'amount'                            => 1,
                'character_level_during_construction' => $character['level'],
                'hp'                                => $buildingBlock['hp'],
                'level'                             => 1,
                'built_at'                          => date('Y-m-d H:i:s'),
                'building_type'                     => 'engineering',
                'tax'                               => $buildingBlock['tax'],
                'usage'                             => $buildingBlock['usage'],
            ];
            $this->characterBuildingModel->insert($data);
        }
    }

    /**
     * Отправляет уведомление пользователю о завершении строительства.
     *
     * @param int $telegramUserId Идентификатор пользователя в базе данных Telegram.
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    private function notifyUser($telegramUserId): \Longman\TelegramBot\Entities\ServerResponse
    {
        $telegramUser = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$telegramUser) {
            return Request::sendMessage([
                'chat_id' => $telegramUserId,
                'text'    => "Ошибка: пользователь не найден.",
            ]);
        }

        $telegram_id = $telegramUser['telegram_id'];

        $text = "📌 Вы успешно построили:\n\n"
            . "*🌀 Центр телепортации*\n\n"
            . "Теперь телепортация доступна по сниженной цене и с расширенным лимитом маяков!";

        $imagePath = base_url('uploads/telegram/camp/teleport_center.png');

        Request::answerCallbackQuery(['callback_query_id' => $telegram_id]);
        try {
            return Request::sendPhoto([
                'chat_id'    => $telegram_id,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            return Request::sendMessage([
                'chat_id' => $telegram_id,
                'text'    => "Произошла ошибка: " . $e->getMessage(),
            ]);
        }
    }
}
