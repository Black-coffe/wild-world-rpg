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
 * Обработчик завершения задачи строительства Арсенала.
 * Вызывается воркером (cron), когда в character_tasks у задачи
 * истекает end_time и status переводится в 'completed'.
 */
class BuiltCompletionArsenalHandler extends Controller
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
            // Обработка исключения или логирование при необходимости
        }
    }

    /**
     * Главный метод обработки завершения задачи.
     *
     * @param array $task Данные задачи (из character_tasks).
     */
    public function handle($task)
    {
        // 1) Ставим статус completed
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Ищем здание "Arsenal"
        $buildingRow = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$buildingRow) {
            // Если вдруг запись не найдена, можно прервать
            return;
        }

        // 3) Добавить/обновить запись в character_buildings
        $this->updateCharacterBuildings($task, $buildingRow);

        // 4) Наградим игрока: к примеру, +0.05 к силе, +0.05 к интеллекту
        // (или любая другая логика)
        $this->characterModel->updateAgilityAndIntellect(
            $task['character_id'],
            0.05,  // +0.05 ловкости
            0.05   // +0.05 интеллекта
        );

        // 5) Уведомить пользователя
        $this->notifyUser($task['telegram_user_id']);
    }

    /**
     * Создаёт/обновляет запись character_buildings для Арсенала.
     *
     * @param array $task
     * @param array $buildingRow (строка из buildings, name_en='Arsenal')
     */
    private function updateCharacterBuildings(array $task, array $buildingRow)
    {
        // Проверяем, нет ли уже здания Arsenal у этого персонажа
        $existing = $this->characterBuildingModel
            ->where('character_id', $task['character_id'])
            ->where('building_id', $buildingRow['id'])
            ->first();

        if ($existing) {
            // Если уже есть, например, просто увеличим amount
            $newAmount = $existing['amount'] + 1;
            $this->characterBuildingModel->update($existing['id'], [
                'amount' => $newAmount
            ]);
        } else {
            // Если нет, тогда создаём новую запись
            // Узнаем фракцию
            $factionRow = (new CharacterFactionModel())
                ->where('character_id', $task['character_id'])
                ->first();
            $factionId = $factionRow ? $factionRow['faction_id'] : null;

            // Получаем персонажа, чтобы взять cell_number и level
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
                // Вы можете дописать поля disappearance_date, usage_count и т.д.
            ]);
        }
    }

    /**
     * Отправляет уведомление игроку, что Арсенал построен.
     */
    private function notifyUser(int $telegramUserId)
    {
        // Извлекаем запись из telegram_users
        $tgUser = $this->telegramUserModel->find($telegramUserId);
        if (!$tgUser) {
            return;
        }
        $tgId = $tgUser['telegram_id'];

        // Текст уведомления
        $text = "🎉 *Поздравляем!*\n\n"
            . "Вы успешно завершили строительство *⚔️ Арсенала*.\n"
            . "Теперь у вас есть здание для хранения и улучшения оружия, производства боеприпасов!\n"
            . "_Время действовать!_";

        // Путь к картинке (или используйте локальный абсолютный путь)
        $imagePath = base_url('uploads/telegram/camp/arsenal.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $tgId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            // Если произошла ошибка при отправке фото,
            // можно fallback'нуть на sendMessage
            Request::sendMessage([
                'chat_id' => $tgId,
                'text'    => "Произошла ошибка при отправке фото: " . $e->getMessage(),
            ]);
        }
    }
}
