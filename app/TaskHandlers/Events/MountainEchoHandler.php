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

/**
 * Класс MountainEchoHandler
 *
 * Обрабатывает событие "MountainEcho" (Горное эхо):
 * 1) С вероятностью 10% (90% пропуск) вызывается логика process().
 * 2) Проверяет, активно ли событие (в ActiveEventModel).
 * 3) Для персонажей в определённом biоме (зависит от eventInfo['biome_ids'])
 *    пытается открыть "новые ячейки" (собранные через MapModel->getSurroundingCells).
 * 4) Количество открываемых ячеек зависит от уровня персонажа, effect_value и специальной таблицы процентов (см. determineNumberOfCells).
 * 5) Отправляет игроку (через Telegram) список новых ячеек (с их координатами, биомами).
 */
class MountainEchoHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $mapModel;
    protected $telegramUserModel;
    private   $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel   = new CharacterModel();
        $this->eventModel       = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->mapModel         = new MapModel();
        $this->telegramUserModel= new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) 10% шанс на запуск (если rand>=10 => return).
     * 2) Проверяем, активно ли "MountainEcho" (eventModel, activeEventModel).
     * 3) Если активно, смотрим biome_ids, выбираем персонажей, чьи biome_id в списке.
     * 4) Для каждого персонажа считаем "сколько ячеек открыть" (determineNumberOfCells).
     * 5) Если >0, вызываем mapModel->getSurroundingCells(...), уведомляем игрока.
     */
    public function process()
    {
        // 1) 10% вероятность
        if (mt_rand(0, 100) >= 10) {
            return; // ~90% случаев событие не обрабатывается
        }

        // 2) Проверяем событие "MountainEcho"
        $eventInfo = $this->eventModel
            ->where('name_english', 'MountainEcho')
            ->first();

        if (!$eventInfo) {
            return; // Не нашли инфу о событии
        }

        // Проверяем, активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'] ?? null)
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return; // Событие не активно
        }

        // Ищем biomes (JSON из eventInfo['biome_ids'])
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds)) {
            return;
        }

        // Находим персонажей, чей biome_id входит в список
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 3) Для каждого персонажа:
        foreach ($characters as $character) {
            // determineNumberOfCells: сколько ячеек открыть
            $numberOfCells = $this->determineNumberOfCells(
                $character['level'],       // уровень персонажа
                $eventInfo['effect_value'] // effect_value из event (пример: 55)
            );

            if ($numberOfCells > 0) {
                // Вызываем mapModel->getSurroundingCells(cell_number, range)
                // где range = $numberOfCells
                $surroundingCells = $this->mapModel->getSurroundingCells(
                    $character['cell_number'],
                    $numberOfCells
                );

                // Уведомляем игрока (telegram_user_id в самом character)
                $this->notifyCharacter($character['telegram_user_id'], $surroundingCells);
            }
        }
    }

    /**
     * Определяем, сколько ячеек (range) можно открыть в map,
     * исходя из уровня персонажа, event effect_value,
     * и таблицы "levelPercentages".
     *
     * Пример:
     *   если level=50 => percent=5,
     *   тогда range = floor((24 * 5 * effectValue)/10000)
     *   или любая другая формула, которую вы заложите.
     */
    private function determineNumberOfCells(int $level, float $effectValue): int
    {
        // Логика/таблица:
        // Для каждого maxLevel => percent
        // levelPercentages[10] => 0.1, [20]=>1, [50]=>5, [100]=>10, etc.
        $levelPercentages = [
            10  => 0.1,
            20  => 1,
            50  => 5,
            100 => 10,
            200 => 20,
            300 => 30,
            400 => 40,
            500 => 50,
            600 => 60,
            700 => 70,
            800 => 80,
            900 => 90,
            999 => 99
        ];

        // Определяем, какой процент взять
        foreach ($levelPercentages as $maxLevel => $percent) {
            if ($level <= $maxLevel) {
                // Примерная формула:
                // range = floor((24 * percent * effectValue)/10000)
                // Можно изменить под нужды
                $val = (24.0 * $percent * $effectValue) / 10000.0;
                return (int)floor($val);
            }
        }

        return 0; // Если уровень слишком высокий или не подошёл
    }

    /**
     * Отправляет игроку сообщение с "соседними ячейками" (surroundingCells),
     * включая их cell_number, координаты (x,y) и имя биома.
     */
    private function notifyCharacter(int $telegramUserId, array $surroundingCells)
    {
        // Находим запись telegramUser
        $telegramUser = $this->telegramUserModel->find($telegramUserId);
        if (!$telegramUser || empty($surroundingCells)) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        // Формируем текст
        $message = "*🎉 Поздравляем!* Событие *'Горное эхо'* дало вам возможность услышать отголоски далеких мест.\n\n";
        $message .= "Вот какие ячейки (координаты/биомы) стали вам доступны:\n\n";

        foreach ($surroundingCells as $cell) {
            // cell_number, x, y, biome_name
            if (isset($cell['cell_number'], $cell['x'], $cell['y'], $cell['biome_name'])) {
                $message .= "🌍 *Ячейка №{$cell['cell_number']}*\n"
                    . "📍 Координаты: `X={$cell['x']}, Y={$cell['y']}`\n"
                    . "🌳 Биом: *{$cell['biome_name']}*\n\n";
            } else {
                log_message('error', "Invalid cell data for one of the surroundingCells.");
            }
        }

        $message .= "🚀 Теперь вы можете наметить новые маршруты или сохранить данные для будущих путешествий.\n"
            . "_Исследуйте мир с умом и наслаждайтесь эхо гор!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению (при желании)
        $photoPath = base_url('uploads/telegram/mountain_echoes.png');

        // Ответ:
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
            log_message('error', "Ошибка при отправке MountainEchoHandler: " . $e->getMessage());
            // Опционально: fallback — обычное текстовое сообщение
        }
    }
}
