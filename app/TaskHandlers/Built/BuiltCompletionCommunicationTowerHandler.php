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

/**
 * Class BuiltCompletionCommunicationTowerHandler
 * Обрабатывает завершение задачи строительства "Вышки связи" (CommunicationTower).
 */
class BuiltCompletionCommunicationTowerHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    private   $telegram;

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
            // Логирование/обработка при необходимости
        }
    }

    /**
     * Главный метод, вызываемый кроном при истечении end_time у задачи в character_tasks.
     * @param array $task Данные задачи (из character_tasks).
     */
    public function handle(array $task)
    {
        // 1) Обновляем статус задачи на 'completed'
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем здание "CommunicationTower" в таблице buildings
        $buildingRow = $this->buildingModel->where('name_en', 'CommunicationTower')->first();
        if (!$buildingRow) {
            // Если нет, просто завершаем
            return;
        }

        // 3) Создаём/обновляем запись в character_buildings
        $this->updateCharacterBuildings($task, $buildingRow);

        // 4) Допустим, даём игроку небольшую награду: +0.05 ловкости и интеллекта
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,
            0.05
        );

        // 5) Уведомляем игрока
        $this->notifyUser($task['telegram_user_id']);
    }

    /**
     * Добавляет или обновляет запись в character_buildings для CommunicationTower.
     *
     * @param array $task Данные задачи: character_id, ...
     * @param array $buildingRow Строка из buildings для CommunicationTower
     */
    private function updateCharacterBuildings(array $task, array $buildingRow)
    {
        // Ищем, есть ли у персонажа уже CommunicationTower
        $existing = $this->characterBuildingModel
            ->where('character_id', $task['character_id'])
            ->where('building_id', $buildingRow['id'])
            ->first();

        if ($existing) {
            // Если есть, допустим, увеличиваем amount
            $newAmount = $existing['amount'] + 1;
            $this->characterBuildingModel->update($existing['id'], [
                'amount' => $newAmount
            ]);
        } else {
            // Иначе создаём новую запись
            $factionRow = $this->characterFactionModel
                ->where('character_id', $task['character_id'])
                ->first();
            $factionId = $factionRow ? $factionRow['faction_id'] : null;

            // Чтобы взять cell_number, нужно глянуть CharacterModel
            $charRow = $this->characterModel->find($task['character_id']);
            if (!$charRow) {
                return;
            }

            $this->characterBuildingModel->insert([
                'character_id'                       => $task['character_id'],
                'building_id'                        => $buildingRow['id'],
                'faction_id'                         => $factionId,
                'map_cell_id'                        => $charRow['cell_number'],
                'amount'                             => 1,
                'character_level_during_construction'=> $charRow['level'],
                'hp'                                 => $buildingRow['hp'],
                'level'                              => 1,
                'built_at'                           => date('Y-m-d H:i:s'),
                'building_type'                      => $buildingRow['building_type'],
                'tax'                                => $buildingRow['tax'],
                'usage'                              => $buildingRow['usage'],
                // при желании - disappearance_date, usage_count, etc.
            ]);
        }
    }

    /**
     * Отправляет игроку сообщение об успешном строительстве Вышки связи.
     *
     * @param int $telegramUserId ID записи в telegram_users (не сам chat_id).
     */
    private function notifyUser(int $telegramUserId)
    {
        // Ищем в telegram_users
        $tgUser = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUser) {
            return;
        }

        // Собственно chat_id
        $tgId = $tgUser['telegram_id'];

        $text = "🎉 *Поздравляем!*\n\n"
            . "Вы завершили строительство *📡Вышки связи*!\n"
            . "Теперь ваша база доступна для удалённого управления.\n"
            . "Каждый уровень — +100 радиус связи!\n\n"
            . "_Удачи в развитии вашей колонии!_";

        // Пусть картинка лежит по этому пути
        $imagePath = base_url('uploads/telegram/camp/communication_tower.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $tgId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            // Если при отправке фото ошибка, fallback
            Request::sendMessage([
                'chat_id' => $tgId,
                'text'    => "Произошла ошибка при отправке фото: " . $e->getMessage(),
            ]);
        }
    }
}
