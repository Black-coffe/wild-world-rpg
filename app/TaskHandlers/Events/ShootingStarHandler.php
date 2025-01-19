<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class ShootingStarHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        if (mt_rand(0, 100) >= 20) {
            return; // 80% шанс на то, что событие не будет обработано
        }

        $eventInfo = $this->eventModel->where('name_english', 'Starfall')->first();
        if (!$eventInfo) {
            return; // If the event is not found, stop execution
        }

        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return; // If the event is not active, stop execution
        }

        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        foreach ($characters as $character) {
            $boostType = $this->selectBoostType();
            $boostValue = $this->calculateBoostValue($boostType);

            if (in_array($boostType, ['health', 'tired', 'gold'])) {
                $boostValue = (int) $boostValue; // Ensure the value is an integer
                if ($character[$boostType] + $boostValue > 100) {
                    $boostValue = 100 - $character[$boostType];
                }
                $this->characterModel->increment($character['id'], $boostType, $boostValue);
            } else {
                $newValue = $character[$boostType] + $boostValue;
                $this->characterModel->update($character['id'], [$boostType => $newValue]);
            }

            $this->notifyCharacter($character, $boostType, $boostValue);
        }
    }

    protected function selectBoostType()
    {
        $types = ['experience', 'health', 'strength', 'agility', 'intellect', 'tired', 'gold'];
        return $types[array_rand($types)];
    }

    protected function calculateBoostValue($type)
    {
        if (in_array($type, ['experience', 'strength', 'agility', 'intellect'])) {
            return rand(1, 111) / 100; // For fractional values
        }
        return rand(1, 100); // For health, tiredness, and gold
    }

    protected function notifyCharacter($character, $boostType, $boostValue)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // If the Telegram user is not found, stop execution
        }

        $chatId = $telegramUserId['telegram_id'];
        $message = sprintf(
            "🌌 *Благодаря событию 'Звездопад'\n\nВаш персонаж получил надбавку к %s в количестве %s.*\n\n" .
            "_Возрадуйтесь вместе с вашим персонажем..._",
            $this->translateBoostType($boostType),
            $boostValue
        );
        $message .= "\n\nПосмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения 👇";

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
        $photo = base_url('uploads/telegram/hundreds_of_shooting_stars.png'); // Необходимо указать реальный путь к изображению

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

    protected function translateBoostType($type)
    {
        $translations = [
            'experience' => 'опыту',
            'health' => 'здоровью',
            'strength' => 'силе',
            'agility' => 'ловкости',
            'intellect' => 'интеллекту',
            'tired' => 'выносливости',
            'gold' => 'золоту'
        ];
        return $translations[$type] ?? 'неизвестному параметру';
    }

}
