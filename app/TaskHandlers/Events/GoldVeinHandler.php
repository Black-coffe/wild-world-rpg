<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel,
    CharacterTaskModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс GoldVeinHandler
 *
 * Обрабатывает событие "GoldMine" (Золотая жила):
 * 1) С вероятностью 10% (90% случаев пропуск) при вызове process().
 * 2) Проверяет, активно ли событие в active_events.
 * 3) Находит персонажей в соответствующих биомах (из eventInfo['biome_ids']).
 * 4) Если у персонажа есть задача "Gather" (статус in_work),
 *    рассчитывает "золото", которое он находит:
 *      - случайная базовая величина (10..15000)
 *      - умножается на (experience+agility+intellect)/3000
 *      - умножается на (effect_value/100)
 * 5) Записываем обновлённое золото в CharacterModel
 * 6) Отправляем уведомление игроку в Telegram с картинкой.
 */
class GoldVeinHandler extends Controller
{
    /** @var CharacterModel */
    protected $characterModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var ActiveEventModel */
    protected $activeEventModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var CharacterTaskModel */
    protected $characterTaskModel;

    /** @var Telegram */
    private $telegram;

    public function __construct()
    {
        $this->characterModel    = new CharacterModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->characterTaskModel= new CharacterTaskModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод процесса:
     * 1) 10% шанс на обработку (если rand>=10 => return).
     * 2) Проверяем активность события 'GoldMine' в active_events.
     * 3) Извлекаем biome_ids и находим персонажей в них.
     * 4) Если у персонажа есть задача "Gather" (in_work),
     *    рассчитываем goldFound и прибавляем к character['gold'].
     * 5) Отправляем уведомление.
     */
    public function process()
    {
        // 1) С вероятностью 6% (если rand>=6, пропускаем)
        if (mt_rand(0, 100) >= 6) {
            return; // 94% случаев событие не будет обработано
        }

        // 2) Ищем событие "GoldMine" (англ. = 'GoldMine')
        $eventInfo = $this->eventModel
            ->where('name_english', 'GoldMine')
            ->first();
        if (!$eventInfo) {
            // Событие "GoldVein" в таблице events не найдено
            return;
        }

        // 2a) Активно ли в active_events?
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            // Неактивное событие => пропуск
            return;
        }

        // 3) Извлекаем набор биомов (biome_ids)
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // Ищем всех персонажей, у которых biome_id входит в список
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 4) Для каждого персонажа проверяем, есть ли задача Gather (in_work)
        foreach ($characters as $character) {
            // Используем прямой SQL через Query Builder:
            $activeTasks = \Config\Database::connect()
                ->table('character_tasks')
                ->select('character_tasks.*')
                ->join('tasks', 'tasks.id = character_tasks.task_id')
                ->where('tasks.name', 'Gather')
                ->where('character_tasks.character_id', $character['id'])
                ->where('character_tasks.status', 'in_work')
                ->get()
                ->getResult();

            // Если есть такая задача (не пустой массив)
            if (!empty($activeTasks)) {
                // Рассчитываем золото
                $goldFound = $this->calculateGoldFound($character, $eventInfo['effect_value']);

                // Прибавляем к character['gold']
                $currentGold = $character['gold'] ?? 0;
                $newGold     = $currentGold + $goldFound;

                // Обновляем запись персонажа
                $this->characterModel->update($character['id'], ['gold' => $newGold]);

                // Уведомляем
                $this->notifyCharacter($character, $goldFound);
            }
        }
    }

    /**
     * Рассчитываем, сколько золота найдено:
     *  - baseGold = rand(1000..150000)
     *  - final = baseGold * ((experience+agility+intellect)/3000) * (effectValue/100)
     *  - округляем
     */
    protected function calculateGoldFound(array $character, float $effectValue): int
    {
        // Извлекаем параметры персонажа
        $experience = $character['experience'];
        $agility    = $character['agility'];
        $intellect  = $character['intellect'];

        // Случайная базовая величина золота
        $baseGold = rand(1000, 150000);

        // Формула
        $finalGold = $baseGold
            * ($experience + $agility + $intellect) / 3000.0
            * ($effectValue / 100.0);

        return round($finalGold);
    }

    /**
     * Уведомление игрока через Telegram, что он нашёл N золота.
     */
    protected function notifyCharacter(array $character, int $goldFound)
    {
        // Ищем запись Telegram-пользователя
        $telegramUserId = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUserId) {
            return;
        }

        $chatId = $telegramUserId['telegram_id'];

        // Формируем текст
        $message = "🌟 *Поздравляем!* Ты наткнулся на золотую жилу.\n\n";
        $message .= "🪙 *Найдено золота:* {$goldFound} единиц\n";
        $message .= "🌍 *Продолжай исследования в поисках новых сокровищ!*";

        // Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж',       'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению (золотые слитки)
        $photoPath = base_url('uploads/telegram/gold_ingots_of_different_sizes_were_found.png');

        // Отправляем фото + caption
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения GoldVein: " . $e->getMessage());
        }
    }
}
