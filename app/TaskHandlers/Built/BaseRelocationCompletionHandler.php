<?php

namespace app\TaskHandlers\Built;

use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterBuildingModel;
use App\Models\TelegramUserModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use CodeIgniter\I18n\Time;

class BaseRelocationCompletionHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $claimedCellModel;
    protected $characterBuildingModel;
    protected $telegramUserModel;
    protected $telegram;

    public function __construct()
    {
        $this->characterModel        = new CharacterModel();
        $this->characterTaskModel    = new CharacterTaskModel();
        $this->claimedCellModel      = new ClaimedCellModel();
        $this->characterBuildingModel= new CharacterBuildingModel();
        $this->telegramUserModel     = new TelegramUserModel();

        // Инициализация Telegram API (аналогично вашим остальным хендлерам)
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
     * Метод handle($task) вызывается воркером, когда время "переезда" (BaseRelocation) подошло к концу.
     * $task — массив из character_tasks, где task['task_id'] ссылается на BaseRelocation, а end_time <= now().
     */
    public function handle(array $task)
    {
        $db = \Config\Database::connect();
        $db->reconnect();

        // 1) Проверяем, что персонаж существует
        $characterId = $task['character_id'] ?? null;
        if (!$characterId) {
            log_message('error', "BaseRelocationCompletionHandler: нет character_id в задаче ID {$task['id']}");
            return;
        }
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            log_message('error', "BaseRelocationCompletionHandler: персонаж ID {$characterId} не найден.");
            return;
        }

        // 2) Ищем ту же задачу в character_tasks: статус должен быть 'in_work', end_time <= now()
        $taskRow = $this->characterTaskModel
            ->where('id', $task['id'])
            ->where('status', 'in_work')
            ->first();

        if (!$taskRow) {
            log_message('debug', "BaseRelocationCompletionHandler: Задача ID {$task['id']} не найдена или не 'in_work'. Прерываем.");
            return;
        }

        // 3) Сравниваем end_time с текущим временем
        $now     = Time::now();
        $endTime = new Time($taskRow['end_time'] ?? 'now');

        if ($endTime->isAfter($now)) {
            // Значит, время ещё не наступило, рано завершать
            log_message('debug', "BaseRelocationCompletionHandler: Задача ID {$task['id']} ещё не достигла end_time.");
            return;
        }

        // 4) Если end_time прошёл → переводим задачу в completed
        $this->characterTaskModel->update($taskRow['id'], [
            'status' => 'completed'
        ]);

        // 5) Удаляем ВСЕ строения игрока
        $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->delete();

        // 6) Удаляем запись из claimed_cells
        $this->claimedCellModel
            ->where('character_id', $characterId)
            ->delete();

        // 7) Уведомляем игрока
        $this->notifyUser($character);
    }

    /**
     * Шлёт сообщение: "Переезд завершён, базу можно разбить заново" и т.д.
     */
    private function notifyUser(array|\App\Entities\CharacterEntity $character)
    {
        // Ищем запись в telegram_users
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser || empty($telegramUser['telegram_id'])) {
            log_message('error', "BaseRelocationCompletionHandler: не найден telegram_id у персонажа ID {$character['id']}");
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $text = "🛠 *Планируемый снос базы завершён!* 🏚\n\n"
            . "Твоя старая база успешно демонтирована, все здания сохранены \"в памяти\". "
            . "Теперь ты можешь в любой момент разбить *новую* базу в удобной локации.\n\n"
            . "Но будь осторожен: смерть без базы приведёт к потере 50% ресурсов!\n\n"
            . "Удачи на новом месте!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Разбить новую базу', 'callback_data' => 'Camp'],
                    ['text' => 'Действия', 'callback_data' => 'characterActions']
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
            log_message('error', "BaseRelocationCompletionHandler: ошибка при отправке сообщения: " . $e->getMessage());
        }
    }
}