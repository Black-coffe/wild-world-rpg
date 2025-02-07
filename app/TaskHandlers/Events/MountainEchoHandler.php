<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    MapModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Добавляем использование сервиса, проверяющего базу
use App\Services\Player\PlayerStateService;

/**
 * Класс MountainEchoHandler
 *
 * Обрабатывает событие "MountainEcho" (Горное эхо):
 * 1) С вероятностью 10% (90% пропуск) вызывается логика process().
 * 2) Проверяем, активно ли событие (activeEvent).
 * 3) Для персонажей в нужных biомах (eventInfo['biome_ids']),
 *    вычисляем, сколько ячеек открыть (determineNumberOfCells).
 * 4) Если персонаж на базе — эффект снижается на 75% (оставляем 25%).
 * 5) Вызов getSurroundingCells() с range = numberOfCells.
 * 6) Уведомляем игрока о новых ячейках.
 */
class MountainEchoHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $mapModel;
    protected $telegramUserModel;

    /** @var PlayerStateService */
    protected $playerStateService;

    /** @var Telegram */
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel   = new CharacterModel();
        $this->eventModel       = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->mapModel         = new MapModel();
        $this->telegramUserModel= new TelegramUserModel();

        // Инициализация PlayerStateService
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) 10% шанс (rand >= 10 => return).
     * 2) Проверяем активность MountainEcho (active_events).
     * 3) Извлекаем биомы, ищем персонажей (biome_id in ...).
     * 4) determineNumberOfCells -> если >0, смотрим, на базе ли персонаж.
     *    Если на базе => снижаем число ячеек на 75%.
     * 5) Вызываем getSurroundingCells(range), уведомляем игрока.
     */
    public function process()
    {
        // 1) Вероятность 10%
        if (mt_rand(0, 100) >= 10) {
            return;
        }

        // 2) Проверяем событие "MountainEcho"
        $eventInfo = $this->eventModel
            ->where('name_english', 'MountainEcho')
            ->first();
        if (!$eventInfo) {
            return;
        }

        // Активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'] ?? null)
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return;
        }

        // 3) Берём список biomes
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds)) {
            return;
        }

        // Ищем персонажей, у кого biome_id в списке
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 4) Для каждого персонажа
        foreach ($characters as $character) {
            // Определяем, сколько ячеек можно открыть
            $numberOfCells = $this->determineNumberOfCells(
                $character['level'],
                $eventInfo['effect_value']
            );

            // Если >0, применяем логику "база => -75%"
            if ($numberOfCells > 0) {
                // Проверяем, находится ли персонаж на базе
                $isOnBase = $this->playerStateService->isCharacterOnBase($character['id']);
                if ($isOnBase) {
                    // Уменьшаем на 75% => 25% остаётся
                    $numberOfCells = (int)floor($numberOfCells * 0.25);
                }

                if ($numberOfCells > 0) {
                    $surroundingCells = $this->mapModel->getSurroundingCells(
                        $character['cell_number'],
                        $numberOfCells
                    );
                    // Уведомляем о новых ячейках
                    $this->notifyCharacter($character['telegram_user_id'], $surroundingCells, $isOnBase);
                }
            }
        }
    }

    /**
     * Считаем, сколько ячеек открыть (range).
     */
    private function determineNumberOfCells(int $level, float $effectValue): int
    {
        // Пример таблицы уровней
        $levelPercentages = [
            10  => 0.1,
            20  => 1,
            50  => 5,
            100 => 10,
            200 => 20,
            300 => 30,
            500 => 50,
            999 => 99
        ];

        foreach ($levelPercentages as $maxLevel => $percent) {
            if ($level <= $maxLevel) {
                // Примерная формула
                $val = (24.0 * $percent * $effectValue) / 10000.0;
                return (int)floor($val);
            }
        }

        return 0;
    }

    /**
     * Уведомление игрока. Если $isOnBase=true, можно добавить фразу,
     * что "Ты на базе, звук эха заглушен, открыто меньше ячеек".
     */
    private function notifyCharacter(int $telegramUserId, array $surroundingCells, bool $isOnBase)
    {
        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser || empty($surroundingCells)) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "*🎉 Горное эхо* донесло до тебя отголоски дальних мест.\n\n";
        if ($isOnBase) {
            $message .= "_Ты находишься на базе, поэтому эхо ослаблено, и удалось открыть меньше ячеек._\n\n";
        }

        $message .= "Вот какие ячейки стали доступны:\n\n";

        foreach ($surroundingCells as $cell) {
            if (isset($cell['cell_number'], $cell['x'], $cell['y'], $cell['biome_name'])) {
                $message .= "🌍 *Ячейка №{$cell['cell_number']}*\n"
                    . "📍 Координаты: `X={$cell['x']}, Y={$cell['y']}`\n"
                    . "🌳 Биом: *{$cell['biome_name']}*\n\n";
            }
        }

        $message .= "_Планируй новые маршруты и вперед к приключениям!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        $photoPath = base_url('uploads/telegram/mountain_echoes.png');

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка отправки MountainEchoHandler: " . $e->getMessage());
        }
    }
}
