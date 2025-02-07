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

// Подключаем сервис для проверки, находится ли персонаж на базе.
use App\Services\Player\PlayerStateService;

/**
 * Класс MirageOasisHandler
 *
 * Обрабатывает событие "MirageOases" (Миражи оазиса).
 * 1) С ~8% вероятностью при вызове process().
 * 2) Проверяет активность события (в active_events).
 * 3) Для персонажей в указанных биомах (biome_ids), которые выполняют Gather/Explore,
 *    применяется эффект: потеря воды, снижение health/tired, увеличение времени задач.
 * 4) Если игрок на базе — эффект снижается на 75%.
 * 5) Уведомляем игрока в Telegram.
 */
class MirageOasisHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterTaskModel;
    protected $telegramUserModel;
    protected $telegram;

    /** @var PlayerStateService */
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel     = new CharacterModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Инициализация PlayerStateService
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram Bot
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) С вероятностью ~8% вызываем логику события.
     * 2) Проверяем активность 'MirageOases'.
     * 3) Ищем биомы (biome_ids), выбираем персонажей в них.
     * 4) Из них берём тех, кто выполняет Gather/Explore (in_work).
     * 5) Применяем эффект: уменьшение воды/здоровья/выносливости, удлинение задач.
     *    Если персонаж на базе — эффект уменьшается на 75%.
     */
    public function process()
    {
        // 1) 8% шанс
        if (mt_rand(1, 100) > 8) {
            return; // ~92% пропуск
        }

        // 2) Проверка события "MirageOases"
        $eventInfo = $this->eventModel
            ->where('name_english', 'MirageOases')
            ->first();
        if (!$eventInfo || !$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return;
        }

        // 3) Список биомов
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // Ищем персонажей, у которых biome_id в списке
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 4) Для каждого персонажа проверяем задачи (Gather/Explore)
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
                continue;
            }

            // 5) Применяем эффект (учитывая "на базе" => -75%)
            $this->applyEffects($character, $activeTasks);
        }
    }

    /**
     * Применяем эффект "мираж":
     * - waterLoss, healthLoss, tirednessLoss (1..10)
     * - Увеличение end_time задач (1..15 минут)
     * - Если на базе => всё умножаем на 0.25 (минус 75%).
     */
    protected function applyEffects(array $character, array $activeTasks)
    {
        // Генерируем базовые потери
        $waterLoss     = rand(1, 10);
        $healthLoss    = rand(1, 10);
        $tirednessLoss = rand(1, 10);

        // Генерируем суммарное продление
        // Прибавляем отдельное случайное время для каждой задачи,
        // в конце посчитаем суммарно
        $totalExtraMinutes = 0;

        // --- Проверяем, на базе ли игрок ---
        $onBase = $this->playerStateService->isCharacterOnBase($character['id']);
        if ($onBase) {
            // Если на базе, уменьшаем эффекты на 75% (т.е. оставляем 25%)
            $waterLoss     = (int)ceil($waterLoss * 0.25);
            $healthLoss    = (int)ceil($healthLoss * 0.25);
            $tirednessLoss = (int)ceil($tirednessLoss * 0.25);
        }

        // Минимальная защита от "ухода в минус"
        $newHealth = max(0.01, $character['health'] - $healthLoss);
        $newTired  = max(0.01, $character['tired']  - $tirednessLoss);

        $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'tired'  => $newTired
        ]);

        // Удлиняем время задач
        foreach ($activeTasks as $task) {
            $extraMinutes = rand(1, 15);

            // Если на базе, сокращаем добавление?
            // Логично, если вы хотите, чтобы на базе и это уменьшалось
            // (Или оставляете дополнительное время неизменным — на ваше усмотрение)
            if ($onBase) {
                $extraMinutes = (int)ceil($extraMinutes * 0.25);
            }
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
        $this->notifyCharacter($character, $waterLoss, $healthLoss, $tirednessLoss, $totalExtraMinutes, $onBase);
    }

    /**
     * Уведомляем игрока о воздействии миража.
     * Если $onBase=true, упоминаем, что эффект ослаблен.
     */
    protected function notifyCharacter(array $character, int $waterLoss, int $healthLoss, int $tiredLoss, int $extraMinutes, bool $onBase)
    {
        $tgUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'] ?? null;
        if (!$chatId) {
            return;
        }

        $message  = "🏜️ *Внимание!* Твой персонаж столкнулся с миражами оазиса.\n\n";
        $message .= "• 💧 Потеря воды: {$waterLoss}\n";
        $message .= "• 🩸 Здоровье: -{$healthLoss}\n";
        $message .= "• 😓 Выносливость: -{$tiredLoss}\n";

        $message .= "\nВсе активные задачи (Gather/Explore) продлены суммарно на *{$extraMinutes}* мин.\n";

        if ($onBase) {
            $message .= "\n_К счастью, ты находился на базе, и эффект миража уменьшен на 75%!_";
        } else {
            $message .= "\n_Пустыня жестока — будь осторожен!_";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        $photoPath = base_url('uploads/telegram/oasis__mirages.png');

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
