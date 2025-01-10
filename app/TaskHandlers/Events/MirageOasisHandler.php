<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\CharacterTaskModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class MirageOasisHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterTaskModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        if (mt_rand(0, 100) >= 2) {
            return; // 98% шанс на то, что событие не будет обработано
        }

        $eventInfo = $this->eventModel->where('name_english', 'MirageOases')->first();
        if (!$eventInfo || !$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return; // Exit if the event is not found or not active
        }

        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        foreach ($characters as $character) {
            $activeTasks = \Config\Database::connect()
                ->table('character_tasks')
                ->select('character_tasks.*')
                ->join('tasks', 'tasks.id = character_tasks.task_id')
                ->whereIn('tasks.name', ['Gather', 'ExploreTheArea'])
                ->where('character_tasks.character_id', $character['id'])
                ->where('character_tasks.status', 'in_work')
                ->get()
                ->getResultArray();

            if (empty($activeTasks)) {
                continue; // Skip characters without active resource gathering or exploration tasks
            }

            // Apply effects
            $this->applyEffects($character, $activeTasks);
        }
    }

    protected function applyEffects($character, $activeTasks)
    {
        // Randomly decrease water supplies, health, and tiredness
        $waterLoss = rand(1, 10);
        $healthLoss = rand(1, 10);
        $tirednessLoss = rand(1, 10);

        // Update character stats
        $newHealth = max(0.01, $character['health'] - $healthLoss);
        $newTiredness = max(0.01, $character['tired'] - $tirednessLoss);

        // Update the character model
        $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'tired' => $newTiredness
        ]);

        // Calculate and store the extra time for tasks
        $totalExtraMinutes = 0;
        foreach ($activeTasks as $task) {
            $extraMinutes = rand(1, 15);
            $totalExtraMinutes += $extraMinutes;
            $newEndTime = date('Y-m-d H:i:s', strtotime($task['end_time'] . " +$extraMinutes minutes"));
            $this->characterTaskModel->update($task['id'], ['end_time' => $newEndTime]);
        }

        // Notify the character
        $this->notifyCharacter($character, $waterLoss, $healthLoss, $tirednessLoss, $totalExtraMinutes);
    }

    protected function notifyCharacter($character, $waterLoss, $healthLoss, $tirednessLoss, $extraMinutes)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // If the Telegram user is not found, stop execution
        }

        $chatId = $telegramUserId['telegram_id'];
        $message = "🏜️ *Внимание!* Вашего персонажа затронули миражи оазиса.\n\n";
        $message .= "💧 Потеря воды: {$waterLoss} единиц,\n🩸 снижение здоровья: {$healthLoss} единиц,\n😓 увеличение усталости: {$tirednessLoss} единиц.\n\n";
        $message .= "Время выполнения задач увеличено на {$extraMinutes} минут.\n";
        $message .= "_Будьте осторожны и старайтесь безопасно передвигаться по пустыне!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        // Путь к изображению для лесного пожара
        $photo = base_url('uploads/telegram/oasis__mirages.png'); // Необходимо указать реальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo' => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения: " . $e->getMessage());
        }
    }
}
