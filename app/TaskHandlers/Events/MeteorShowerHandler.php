<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class MeteorShowerHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $characterResourceModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->biomeModel = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Initialize Telegram Bot (Assuming Telegram Bot setup is correct)
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        // Получаем данные о событии "Метеоритный дождь"
        $meteorEvent = $this->eventModel->where('name_english', 'MeteorRain')->first();
        if (!$meteorEvent) {
            log_message('error', "Событие 'Метеоритный дождь' не найдено.");
            return;
        }

        // Проверяем, активно ли событие
        $isActive = $this->activeEventModel->where([
            'event_id' => $meteorEvent['event_id'],
            'status' => 'active',
            'end_time >=' => date('Y-m-d H:i:s')  // Убедимся, что событие все еще не завершилось
        ])->first();

        if (!$isActive) {
            return;  // Если событие не активно, выходим из метода
        }

        // Randomly select 50 game cells
        $selectedCells = $this->selectRandomCells(1000);

        // Добавить тестовую ячейку '56451' в список выбранных ячеек
        //$selectedCells[] = 56451;

        // Find characters in these cells
        $affectedCharacters = $this->characterModel->whereIn('cell_number', $selectedCells)->findAll();

        foreach ($affectedCharacters as $character) {
            if (mt_rand(0, 1) === 0) {
                // Apply resource damage
                $this->applyResourceDamage($character);
            } else {
                // Apply personal damage
                $this->applyPersonalDamage($character);
            }
        }
    }

    protected function selectRandomCells($count)
    {
        $cells = [];
        for ($i = 0; $i < $count; $i++) {
            $cells[] = mt_rand(1, 1000000);
        }
        return $cells;
    }

    protected function applyResourceDamage($character)
    {
        $resources = $this->characterResourceModel->where('id_characters', $character['id'])->findAll();
        $effectValue = $this->eventModel->where('name_english', 'MeteorRain')->first()['effect_value'];

        foreach ($resources as $resource) {
            $newQuantity = max(1, $resource['quantity'] - ceil($resource['quantity'] * ($effectValue / 100)));
            $this->characterResourceModel->update($resource['id'], ['quantity' => $newQuantity]);
        }
        $this->notifyCharacter($character, "resource damage", $effectValue);
    }

    protected function applyPersonalDamage($character)
    {
        $effectValue = $this->eventModel->where('name_english', 'MeteorRain')->first()['effect_value'];
        $newHealth = max(0.01, $character['health'] - ($character['health'] * ($effectValue / 100)));
        $newTiredness = max(0.01, $character['tired'] - ($character['tired'] * ($effectValue / 100)));

        $this->characterModel->update($character['id'], ['health' => $newHealth, 'tired' => $newTiredness]);
        $this->notifyCharacter($character, "personal damage", $effectValue);
    }

    protected function notifyCharacter($character, $type, $effectValue)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Если телеграм-пользователь не найден, выходим из метода
        }
        $chatId = $telegramUserId['telegram_id']; // ID чата пользователя в Telegram
        $message = "⚠️ *Внимание! * Метеоритный дождь повлиял на вашего персонажа. \n\n";

        if ($type === "resource damage") {
            $message .= "Ваши *ресурсы* были сокращены *на {$effectValue}%* из-за падения метеорита.";
        } else {
            $message .= "Ваше *здоровье и усталость* были снижены *на {$effectValue}%* из-за падения метеорита.";
        }
        $message .= "_Вам нужно укрыться в бункере, если вы его построили во избежание смерти!_ ☠️\n\n";
        $message .= "Посмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        // Путь к изображению для лесного пожара
        $photo = base_url('uploads/telegram/due_to_meteor_impact.png'); // Необходимо указать реальный путь к изображению

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
