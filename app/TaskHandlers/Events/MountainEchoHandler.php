<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{CharacterModel, EventModel, ActiveEventModel, MapModel, TelegramUserModel};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class MountainEchoHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $mapModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализация Telegram Bot
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        if (mt_rand(0, 100) >= 10) {
            return; // 90% шанс на то, что событие не будет обработано
        }

        // Проверяем активность события "Горное эхо"
        $eventInfo = $this->eventModel->where('name_english', 'MountainEcho')->first();

        if (!$eventInfo) {
            return; // Если информация о событии не найдена, прерываем выполнение
        }

        // Check if the event ID exists and the event is active
        $activeEvent = $this->activeEventModel->where('event_id', $eventInfo['event_id'] ?? null)->where('status', 'active')->first();
        if (!$activeEvent) {
            return; // Если событие не активно, прерываем выполнение
        }

        // Если событие активно, продолжаем обработку
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds)) {
            return;
        }

        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        foreach ($characters as $character) {
            $numberOfCells = $this->determineNumberOfCells($character['level'], $eventInfo['effect_value']);

            if ($numberOfCells > 0) {
                $surroundingCells = $this->mapModel->getSurroundingCells($character['cell_number'], $numberOfCells);
                $this->notifyCharacter($character['telegram_user_id'], $surroundingCells);
            }
        }
    }

    private function determineNumberOfCells($level, $effectValue)
    {
        // Логика определения количества ячеек, которые можно открыть
        $levelPercentages = [
            10 => 0.1, 20 => 1, 50 => 5, 100 => 10, 200 => 20,
            300 => 30, 400 => 40, 500 => 50, 600 => 60, 700 => 70,
            800 => 80, 900 => 90, 999 => 99
        ];

        foreach ($levelPercentages as $maxLevel => $percent) {
            if ($level <= $maxLevel) {
                return floor((24 * $percent * $effectValue) / 10000);
            }
        }
        return 0;  // В случае если уровень не попадает под условия
    }

    private function notifyCharacter($telegramUserId, $surroundingCells)
    {
        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser || empty($surroundingCells)) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];
        $message = "*🎉 Поздравляем с новыми открытиями!*\n\nСобытие *'Горное эхо'* помогло вам раскрыть тайны окружающих территорий:\n\n";

        foreach ($surroundingCells as $cell) {
            if (isset($cell['cell_number'], $cell['x'], $cell['y'], $cell['biome_name'])) {
                $message .= "🌍 *Ячейка №{$cell['cell_number']}*\n"
                    . "📍 Координаты: `X={$cell['x']}, Y={$cell['y']}`\n"
                    . "🌳 Биом: *{$cell['biome_name']}*\n\n";
            } else {
                log_message('error', "Invalid cell data provided for notification.");
            }
        }

        $message .= "🚀 Можете переехать в любую из открытых локаций или сохранить эти данные для будущих приключений.\n\n"
            . "*Исследуйте мир вокруг с умом и осторожностью!*";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению (нужно заменить на актуальный путь)
        $photo = base_url('uploads/telegram/mountain_echoes.png'); // Укажите актуальный путь к изображению

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
