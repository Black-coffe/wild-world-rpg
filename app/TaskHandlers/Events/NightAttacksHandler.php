<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel; // Добавлено для проверки задач
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class NightAttacksHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterTaskModel; // Добавлено для проверки задач
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->characterTaskModel = new CharacterTaskModel(); // Инициализировано для проверки задач
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

        $currentTime = date('H:i');
        if (!($currentTime >= '20:00' || $currentTime <= '05:00')) {
            log_message('error', 'Exit if not within the specified night time range');
            return; // Exit if not within the specified night time range
        }

        $eventInfo = $this->eventModel->where('name_english', 'NightAttacks')->first();
        if (!$eventInfo) {
            log_message('error', 'Exit if the event is not found');
            return; // Exit if the event is not found
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$activeEvent) {
            log_message('error', 'Активное событие "NightAttacks" не найдено');
            return; // Активное событие "NightAttacks" не найдено
        }

        $affectedCharacters = $this->characterModel->findAll();

        foreach ($affectedCharacters as $character) {
            // Проверка активных задач перед применением урона
            $activeTasks = $this->characterTaskModel
                ->where('character_id', $character['id'])
                ->whereIn('task_id', [1, 3]) // Задачи должны быть перечислены как в вашей БД
                ->where('status', 'in_work')
                ->findAll();

            if (empty($activeTasks)) {
                continue; // Пропустить персонажей без активных задач
            }

            $this->applyNightDamage($character, $eventInfo['effect_value']);
        }
    }

    protected function applyNightDamage($character, $effectValue)
    {
        $newHealth = max(0.01, $character['health'] - ($character['health'] * ($effectValue / 100)));
        $newTiredness = max(0.01, $character['tired'] - ($character['tired'] * ($effectValue / 100)));
        $this->characterModel->update($character['id'], ['health' => $newHealth, 'tired' => $newTiredness]);
        $this->notifyCharacter($character, $effectValue);
    }

    protected function notifyCharacter($character, $effectValue)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Exit if the Telegram user is not found
        }
        $chatId = $telegramUserId['telegram_id'];
        $message = "⚠️ *Внимание!* Ночные нападения повлияли на вашего персонажа.\n\n";
        $message .= "*Здоровье и усталость* снижены на *{$effectValue}%* из-за агрессии диких животных.\n\n";
        $message .= "🌌 *Обязательно найдите убежище или вернитесь к себе домой.* _Во избежание смерти!_ ☠️\n\n";

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
        $photo = base_url('uploads/telegram/night__attacks.png'); // Необходимо указать реальный путь к изображению

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
