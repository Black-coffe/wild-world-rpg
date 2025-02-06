<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    CharacterTaskModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс MirageOasisHandler
 *
 * Обрабатывает событие "MirageOases" (Миражи оазиса).
 * 1) С ~8% вероятностью (92% пропуск) при вызове process().
 * 2) Проверяет, активно ли событие (в active_events).
 * 3) Извлекает список биомов (biome_ids) из EventModel.
 * 4) Для персонажей в этих биомах, если у них задачи Gather/Explore (in_work),
 *    применяется эффект: потеря воды (напрямую не видно, но условно),
 *    снижение health и tired, а также увеличение времени выполнения задач.
 * 5) Уведомляет игрока в Telegram (с картинкой) о воздействии миража.
 */
class MirageOasisHandler extends Controller
{
    /** @var CharacterModel */
    protected $characterModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var ActiveEventModel */
    protected $activeEventModel;

    /** @var CharacterTaskModel */
    protected $characterTaskModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var Telegram */
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel    = new CharacterModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->characterTaskModel= new CharacterTaskModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализация Telegram Bot
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) С вероятностью 8% вызываем логику события (92% случаев не обрабатываем).
     * 2) Находим событие 'MirageOases' в EventModel, проверяем активность (active).
     * 3) Ищем biomes, где действует событие, получаем персонажей, у которых biome_id в этом списке.
     * 4) Из них выбираем тех, кто сейчас выполняет Gather/Explore (in_work).
     * 5) Применяем эффект: уменьшение здоровья/выносливости и удлинение задач.
     */
    public function process()
    {
        // 1) 8% шанс
        if (mt_rand(0, 100) >= 8) {
            return; // 92% шанс пропуска
        }

        // 2) Проверяем событие "MirageOases" (англ. name_english)
        $eventInfo = $this->eventModel
            ->where('name_english', 'MirageOases')
            ->first();

        // Если не найдено или не активно, завершаем
        if (!$eventInfo || !$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return;
        }

        // 3) Список биомов из поля biome_ids
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // Ищем персонажей, у которых biome_id в $biomeIds
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 4) Для каждого персонажа:
        //    Проверяем, есть ли задачи (Gather, ExploreTheArea) in_work
        foreach ($characters as $character) {
            $activeTasks = \Config\Database::connect()
                ->table('character_tasks')
                ->select('character_tasks.*')
                ->join('tasks', 'tasks.id = character_tasks.task_id')
                ->whereIn('tasks.name', ['Gather', 'ExploreTheArea'])
                ->where('character_tasks.character_id', $character['id'])
                ->where('character_tasks.status', 'in_work')
                ->get()
                ->getResultArray();

            if (empty($activeTasks)) {
                // Нет подходящих задач => эффекта нет
                continue;
            }

            // 5) Применяем эффект события (потеря воды, health, tired + увеличение времени)
            $this->applyEffects($character, $activeTasks);
        }
    }

    /**
     * Применяем эффект "мираж" к персонажу:
     * - waterLoss (1..10) — условное (здесь просто как текст)
     * - healthLoss (1..10)
     * - tirednessLoss (1..10)
     * - tasks end_time += random(1..15) минут
     */
    protected function applyEffects(array $character, array $activeTasks)
    {
        // Случайные потери
        $waterLoss     = rand(1, 10);
        $healthLoss    = rand(1, 10);
        $tirednessLoss = rand(1, 10);

        // Обновляем здоровье/выносливость
        $newHealth     = max(0.01, $character['health'] - $healthLoss);
        $newTired      = max(0.01, $character['tired']  - $tirednessLoss);

        // Запись
        $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'tired'  => $newTired
        ]);

        // Удлиняем время задач (сложнее ориентироваться в пустыне)
        $totalExtraMinutes = 0;
        foreach ($activeTasks as $task) {
            $extraMinutes = rand(1, 15); // 1..15
            $totalExtraMinutes += $extraMinutes;

            $newEndTime = date(
                'Y-m-d H:i:s',
                strtotime($task['end_time'] . " +{$extraMinutes} minutes")
            );
            $this->characterTaskModel->update($task['id'], [
                'end_time' => $newEndTime
            ]);
        }

        // Уведомляем
        $this->notifyCharacter($character, $waterLoss, $healthLoss, $tirednessLoss, $totalExtraMinutes);
    }

    /**
     * Уведомление в Telegram:
     * - Показываем, сколько воды "потрачено" (waterLoss),
     *   healthLoss, tirednessLoss, суммарно добавили X минут к задачам.
     */
    protected function notifyCharacter(array $character, int $waterLoss, int $healthLoss, int $tirednessLoss, int $extraMinutes)
    {
        // Ищем телеграм-пользователя
        $telegramUserId = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUserId) {
            return;
        }

        $chatId = $telegramUserId['telegram_id'];
        if (!$chatId) {
            return;
        }

        $message  = "🏜️ *Внимание!* Твой персонаж столкнулся с миражами оазиса.\n\n";
        $message .= "• 💧 Потеря воды: {$waterLoss}\n";
        $message .= "• 🩸 Здоровье уменьшилось на: {$healthLoss}\n";
        $message .= "• 😓 Усталость увеличилась на: {$tirednessLoss}\n\n";
        $message .= "Все активные задачи (Gather/Explore) стали дольше на *{$extraMinutes}* минут.\n";
        $message .= "_Будь осторожен: пустыня не прощает ошибок!_\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Изображение
        $photoPath = base_url('uploads/telegram/oasis__mirages.png');

        // Отправка
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
            log_message('error', "Ошибка при отправке сообщения MirageOasis: " . $e->getMessage());
        }
    }
}
