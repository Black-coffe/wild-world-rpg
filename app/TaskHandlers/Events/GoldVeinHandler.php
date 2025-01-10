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

class GoldVeinHandler extends Controller
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

        $eventInfo = $this->eventModel->where('name_english', 'GoldMine')->first();
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
                ->where('tasks.name', 'Gather')
                ->where('character_tasks.character_id', $character['id'])
                ->where('character_tasks.status', 'in_work')
                ->get()
                ->getResult();

            if (!empty($activeTasks)) {
                $goldFound = $this->calculateGoldFound($character, $eventInfo['effect_value']);
                $this->characterModel->update($character['id'], ['gold' => ($character['gold'] ?? 0) + $goldFound]);

                // Notify character via Telegram
                $this->notifyCharacter($character, $goldFound);
            }
        }
    }

    protected function calculateGoldFound($character, $effectValue)
    {
        $experience = $character['experience'];
        $agility = $character['agility'];
        $intellect = $character['intellect'];
        $baseGold = rand(10, 15000); // Random gold found

        // Calculate final gold found based on character attributes and event effect value
        $finalGold = $baseGold * ($experience + $agility + $intellect) / 3000 * ($effectValue / 100);
        return round($finalGold);
    }

    protected function notifyCharacter($character, $goldFound)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Exit if the Telegram user is not found
        }
        $chatId = $telegramUserId['telegram_id'];
        $message = "🌟 *Поздравляем!* Вы нашли золотую жилу.\n\n";
        $message .= "🪙 *Найдено золота:* {$goldFound} единиц\n";
        $message .= "🌍 *Продолжайте исследования в поисках новых сокровищ!*";

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
        $photo = base_url('uploads/telegram/gold_ingots_of_different_sizes_were_found.png'); // Необходимо указать реальный путь к изображению

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
