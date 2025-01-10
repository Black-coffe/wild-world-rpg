<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class SnowFallHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();

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
        
        $eventInfo = $this->eventModel->where('name_english', 'Snowfall')->first();
        if (!$eventInfo) {
            return; // If the event "GoldVein" is not found, stop execution
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$activeEvent) {
            return; // If the event "GoldVein" is not active, stop execution
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
                ->getResult();

            if (!empty($activeTasks)) {
                $damageType = rand(0, 1) ? 'health' : 'tired'; // Случайный выбор между здоровьем и выносливостью
                $damageAmount = rand(1, 90); // Случайное значение урона от 1 до 10

                // Применяем урон к персонажу, обеспечиваем, что значение не упадет ниже 0.01
                $newValue = max(0.01, $character[$damageType] - $damageAmount);
                $this->characterModel->update($character['id'], [$damageType => $newValue]);

                // Отправляем уведомление в Telegram
                $this->notifyCharacter($character, $damageType, $damageAmount);
            }
        }
    }

    protected function notifyCharacter($character, $damageType, $damageAmount)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Если Telegram пользователя не найдено, прекращаем выполнение
        }

        $chatId = $telegramUserId['telegram_id'];
        $damageTypeText = $damageType === 'health' ? 'здоровью' : 'выносливости';
        $message = "⚠️ *Внимание!* Снежный обвал нанес урон персонажу.\n\n";
        $message .= "🌨 *Потеряно {$damageAmount} единиц* {$damageTypeText}.\n\n";
        $message .= "🏔 *Оставайтесь в безопасности и избегайте опасных зон!*\n";
        $message .= "_Вам нужно укрыться на базе, во избежание смерти!_ ☠️\n\n";
        $message .= "Посмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения 👇";

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
        $photo = base_url('uploads/telegram/heavy_snowfalls_cause_avalanches_and_snowfalls.png'); // Необходимо указать реальный путь к изображению

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
