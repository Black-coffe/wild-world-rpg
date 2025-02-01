<?php

namespace app\TaskHandlers\Built;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterBuildingModel;
use App\Models\TelegramUserModel;

use CodeIgniter\Controller;
use CodeIgniter\I18n\Time;

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Класс обрабатывает завершение Задачи "FullRelocation" (полноценного переезда).
 * Вызывается воркером, когда end_time <= now().
 */
class BaseFullRelocationCompletionHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $claimedCellModel;
    protected $characterBuildingModel;
    protected $telegramUserModel;
    protected $telegram;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->telegramUserModel      = new TelegramUserModel();

        // Инициализация Telegram API
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
     * Метод handle($taskRow) вызывается воркером/крон-скриптом,
     * когда время задачи "FullRelocation" (status='in_work', end_time <= now()) подошло к концу.
     *
     * @param array $taskRow Запись из character_tasks
     */
    public function handle(array $taskRow)
    {
        // (Опционально) небольшая пауза, если нужно
        // sleep(10);

        $db = \Config\Database::connect();
        $db->reconnect();

        // 1) Проверяем наличие персонажа
        $characterId = $taskRow['character_id'] ?? null;
        if (!$characterId) {
            log_message('error', "BaseFullRelocationCompletionHandler: нет character_id в задаче ID {$taskRow['id']}.");
            return;
        }
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "BaseFullRelocationCompletionHandler: персонаж ID {$characterId} не найден.");
            return;
        }

        // 2) Проверяем, что задача действительно 'in_work'
        $rowInDb = $this->characterTaskModel
            ->where('id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if (!$rowInDb) {
            log_message('debug', "BaseFullRelocationCompletionHandler: Задача ID {$taskRow['id']} не найдена или не 'in_work'. Пропускаем.");
            return;
        }

        // 3) Проверяем, наступило ли end_time
        $now     = Time::now();
        $endTime = new Time($rowInDb['end_time'] ?? 'now');
        if ($endTime->isAfter($now)) {
            // Ещё не время
            return;
        }

        // 4) Читаем task_settings: там должен лежать "new_map_cell_id"
        $settings = json_decode($rowInDb['task_settings'] ?? '{}', true);
        $newMapCellId = $settings['new_map_cell_id'] ?? null;
        if (!$newMapCellId) {
            log_message('error', "BaseFullRelocationCompletionHandler: нет new_map_cell_id в task_settings задачи ID {$taskRow['id']}.");
            // Завершаем задачу, но без переноса
            $this->characterTaskModel->update($rowInDb['id'], ['status'=>'completed']);
            $this->notifyUser($character, "Переезд завершён, но new_map_cell_id не найден! База осталась на месте?");
            return;
        }

        // 5) Завершаем задачу (status='completed')
        $this->characterTaskModel->update($rowInDb['id'], ['status' => 'completed']);

        // 6) Ищем старую запись claimed_cells.
        //    Если найдём — просто обновим 'map_cell_id'. Если нет — создадим новую.
        $oldClaimed = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->first();

        if ($oldClaimed) {
            // Просто меняем map_cell_id
            $this->claimedCellModel->update($oldClaimed['id'], [
                'map_cell_id' => $newMapCellId,
                'status'      => 'active',
                'claimed_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            // Если не нашли, создаём
            $this->claimedCellModel->insert([
                'character_id' => $characterId,
                'map_cell_id'  => $newMapCellId,
                'status'       => 'active',
                'claimed_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // 7) Обновляем character_buildings: переносим на new_map_cell_id
        //    (если хотим "перенести" здания).
        //    Или, при желании, можно использовать массив 'character_buildings' из task_settings —
        //    но по вашему условию, достаточно массового update.
        $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->set(['map_cell_id' => $newMapCellId])
            ->update();

        // 8) (Опционально) можно передавать
        // if (!empty($settings['someOtherData'])) { ... }

        // 9) Уведомляем игрока об успехе
        $this->notifyUser($character, "✅ *Полноценный переезд завершён!* Твоя база теперь в новой локации!");
    }

    /**
     * Уведомляем игрока в Telegram
     *
     * @param array $character Запись персонажа (из characterModel)
     * @param string $msg Текст сообщения
     */
    private function notifyUser(array $character, string $msg)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "BaseFullRelocationCompletionHandler: не найден telegram_id у персонажа {$character['id']}.");
            return;
        }

        $chatId = $telegramUser['telegram_id'];

        $text = $msg . "\n\n"
            . "Теперь можешь в любой момент проверить свою новую базу (нажми кнопку или введи /camp).";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Проверить базу', 'callback_data' => 'Base'],
                    ['text' => 'Действия',       'callback_data' => 'characterActions'],
                ],
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "BaseFullRelocationCompletionHandler: ошибка при отправке сообщения: " . $e->getMessage());
        }
    }
}