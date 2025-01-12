<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{CharacterModel, TaskModel, EventModel, BiomeModel, ResourceModel, CharacterTaskModel, CharacterResourceModel, TelegramUserModel};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class DungeonOpeningHandler extends Controller
{
    protected $characterModel;
    protected $telegramUserModel;
    protected $eventModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $taskModel;
    protected $characterTaskModel;
    protected $characterResourceModel;
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->eventModel = new EventModel();
        $this->biomeModel = new BiomeModel();
        $this->taskModel = new TaskModel();
        $this->resourceModel = new ResourceModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->characterResourceModel = new CharacterResourceModel();

        // Инициализация Telegram Bot
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        // Идентификация активного события и биома
        $eventInfo = $this->eventModel->where('name_english', 'RevealingHiddenCameras')->first();
        if (!$eventInfo) return;

        $caveBiomes = $this->biomeModel->where('biome_type', 'cave')->findAll();
        $caveBiomeIds = array_column($caveBiomes, 'id');

        // Поиск персонажей в биомах типа 'cave'
        $charactersInCaves = $this->characterModel->whereIn('biome_id', $caveBiomeIds)->findAll();

        foreach ($charactersInCaves as $character) {
            // Проверка задания на добычу ресурсов
            $gatherTaskId = $this->getGatherTaskId();
            $isGathering = $this->characterTaskModel->where([
                'character_id' => $character['id'],
                'task_id' => $gatherTaskId,
                'status' => 'in_work'
            ])->first();

            if ($isGathering) {
                $this->handleResourceAllocation($character, $eventInfo['effect_value']);
            }
        }
    }

    private function getGatherTaskId()
    {
        $task = $this->taskModel->where('name', 'Gather')->first();
        return $task ? $task['id'] : null;
    }

    private function handleResourceAllocation($character, $effectValue)
    {
        $biomeResources = $this->resourceModel->where('biome_id', $character['biome_id'])
            ->where('rarity', 1)
            ->where('keyword', 'RevealingHiddenCameras')
            ->findAll();

        if (empty($biomeResources)) {
            log_message('error', "No resources found for biome ID: {$character['biome_id']}");
            return;
        }

        // Выбираем один случайный ресурс из списка доступных
        $resource = $biomeResources[array_rand($biomeResources)];

        // Расчёт количества ресурса на основе уровня персонажа
        $amount = $this->calculateResourceAmount($character['level'], $resource['price']);

        if ($amount <= 0) {
            return; // Если количество равно 0 или меньше, прерываем выполнение функции
        }

        // Поиск существующего ресурса персонажа
        $existingResource = $this->characterResourceModel->where([
            'id_characters' => $character['id'],
            'id_resources' => $resource['id']
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] + $amount;
            $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->insert([
                'id_characters' => $character['id'],
                'id_resources' => $resource['id'],
                'quantity' => $amount
            ]);
        }

        // Отправка уведомления в Telegram
        $this->notifyCharacter($character, $resource['name'], $amount);
    }

    private function calculateResourceAmount($level, $price)
    {
        $percentage = 0; // Начальное значение процента
        if ($level <= 10) {
            $percentage = 0.1;
        } elseif ($level <= 20) {
            $percentage = 1;
        } elseif ($level <= 50) {
            $percentage = 5;
        } elseif ($level <= 100) {
            $percentage = 10;
        } elseif ($level <= 200) {
            $percentage = 20;
        } elseif ($level <= 300) {
            $percentage = 30;
        } elseif ($level <= 400) {
            $percentage = 40;
        } elseif ($level <= 500) {
            $percentage = 50;
        } elseif ($level <= 600) {
            $percentage = 60;
        } elseif ($level <= 700) {
            $percentage = 70;
        } elseif ($level <= 800) {
            $percentage = 80;
        } elseif ($level <= 900) {
            $percentage = 90;
        } else {
            $percentage = 99;
        }

        return floor($percentage / 1000 * $price); // Использование floor для гарантии целого числа, может быть 0
    }

    private function notifyCharacter($character, $resourceName, $amount)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "🎉 Поздравляем!\n\nВы нашли *{$amount}* еднц. ресурса {$resourceName}\n\nблагодаря событию *Открытие скрытых подземелий*.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению (нужно заменить на актуальный путь)
        $photo = base_url('uploads/telegram/dragon_heart.png'); // Укажите актуальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            return Request::sendPhoto([
                'chat_id' => $chatId,
                'photo' => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            // Можете отправить сообщение без изображения или с другим изображением
        }
    }
}
