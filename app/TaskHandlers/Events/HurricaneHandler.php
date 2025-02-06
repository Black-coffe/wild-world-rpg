<?php
namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    BiomeModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Сервис, позволяющий проверить, где находится персонаж (база/не база),
// какие задачи (Gather/Explore) у него активны и т.д.
use App\Services\Player\PlayerStateService;

/**
 * Класс HurricaneHandler
 *
 * Обрабатывает событие "Hurricane" (Ураган):
 * 1) С вероятностью ~35% запускается логика (65% случаев пропускаем).
 * 2) Проверяем, действительно ли событие активно (active_events).
 * 3) Собираем персонажей, которые находятся в целевых биомах и заняты задачами
 *    (ExploreTheArea или Gather).
 * 4) Из них рандомно выбираем одного (array_rand) и применяем ураганные эффекты:
 *    - Если персонаж на базе, урон = 0, уведомляем о «спасении».
 *    - Иначе рассчитываем урон по формуле (учитывая effect_value, danger_level, выживание, уровень),
 *      если персонаж *не* Gather/Explore, урон вдвое меньше.
 * 5) Уменьшаем здоровье (min=0.01), уведомляем игрока (с картинкой).
 */
class HurricaneHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $playerStateService; // Сервис проверок
    private   $telegram;

    public function __construct()
    {
        // Инициализируем модели
        $this->characterModel    = new CharacterModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->biomeModel        = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Сервис, который проверяет, находится ли персонаж на базе,
        // занят ли Gather/Explore и т.д.
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $this->initTelegram();
    }

    /**
     * Инициализация Telegram SDK
     */
    private function initTelegram()
    {
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Основной метод, вызываемый cron/игровым циклом:
     * 1) 35% шанс, иначе пропускаем.
     * 2) Проверяем активность "Hurricane".
     * 3) Получаем "список" потенциально затронутых персонажей.
     * 4) Из списка выбираем одного рандомно, применяем ураганный урон.
     */
    public function process()
    {
        // 1) Вероятность ~35%
        if (mt_rand(1, 100) > 35) {
            return; // ~65% случаев событие не обрабатывается
        }

        // 2) Проверяем, активно ли событие
        $activeEvent = $this->isEventActive('Hurricane');
        if (!$activeEvent) {
            // Событие неактивно
            return;
        }

        // 3) Получаем список персонажей, которые потенциально могут пострадать
        $affectedCharacters = $this->getAffectedCharacters();
        if (empty($affectedCharacters)) {
            // Никто не подходит
            return;
        }

        // 4) Выбираем одного случайного
        $selectedCharacterId = $affectedCharacters[array_rand($affectedCharacters)];

        // Применяем эффекты урагана
        $this->applyHurricaneEffects($selectedCharacterId);
    }

    /**
     * Проверка активности события: ищем запись в events (по name_english='Hurricane'),
     * затем сверяем в active_events (status='active').
     *
     * Если всё ок, возвращаем массив с 'biome_ids','effect_value'; иначе false
     */
    protected function isEventActive(string $eventNameEnglish)
    {
        $eventInfo = $this->eventModel
            ->where('name_english', $eventNameEnglish)
            ->first();
        if (!$eventInfo || empty($eventInfo['biome_ids'])) {
            // Нет записи или пустые biomes
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Добавим нужные поля
            $activeEvent['biome_ids']    = $eventInfo['biome_ids'];
            $activeEvent['effect_value'] = $eventInfo['effect_value'];
            return $activeEvent;
        }

        return false;
    }

    /**
     * Возвращаем массив ID персонажей, которые:
     * - находятся в биомах из hurricane
     * - имеют задачу ExploreTheArea или Gather (in_work)
     */
    protected function getAffectedCharacters()
    {
        // Проверяем событие "Hurricane"
        $active = $this->isEventActive('Hurricane');
        if (!$active) {
            return [];
        }

        // biomes
        $biomeIds = json_decode($active['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return [];
        }

        // Подключаемся к БД
        $db = db_connect();

        // Ищем ID задач "ExploreTheArea" или "Gather"
        $taskIds = $db->table('tasks')
            ->whereIn('name', ['ExploreTheArea', 'Gather'])
            ->select('id')
            ->get()
            ->getResultArray();

        $taskIdsArray = array_column($taskIds, 'id');

        // Выбираем персонажей, у которых есть character_tasks (task_id in ..., in_work)
        // И biome_id in $biomeIds
        $characters = $db->table('character_tasks')
            ->select('character_tasks.character_id')
            ->join('characters', 'characters.id = character_tasks.character_id')
            ->whereIn('character_tasks.task_id', $taskIdsArray)
            ->where('character_tasks.status', 'in_work')
            ->whereIn('characters.biome_id', $biomeIds)
            ->groupBy('character_tasks.character_id')
            ->get()
            ->getResultArray();

        // array_column(...,'character_id')
        return array_column($characters, 'character_id');
    }

    /**
     * Применяем ураганные эффекты к конкретному персонажу:
     * - Если на базе => уведомляем о спасении, урона нет.
     * - Иначе считаем damage = calculateDamageForCharacter()
     *   Если персонаж *не* Gather/*не* Explore => damage *= 0.5
     * - Уменьшаем health, шлём сообщение.
     */
    protected function applyHurricaneEffects(int $characterId)
    {
        // Ищем персонажа
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return;
        }

        // 1) Проверка базы
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            $this->notifySafeOnBase($character);
            return;
        }

        // 2) Считаем damage (полный),
        $fullDamage = $this->calculateDamageForCharacter($character);

        // Если персонаж не Gather/Explore, урон только 50%
        $isGather   = $this->playerStateService->isGathering($characterId);
        $isExplore  = $this->playerStateService->isExploring($characterId);

        $damage = $fullDamage;
        if (!$isGather && !$isExplore) {
            $damage *= 0.5;
        }
        $damage = round($damage, 2);

        // 3) Уменьшаем здоровье, не ниже 0.01
        $newHealth = max(0.01, $character['health'] - $damage);

        $this->characterModel->update($characterId, [
            'health' => $newHealth
        ]);

        // 4) Уведомляем персонажа
        $this->notifyCharacter($character, $damage, $newHealth);
    }

    /**
     * Примерная формула урона:
     *  - Берём effect_value (примерно 65)
     *  - Учитываем danger_level, survival_difficulty,
     *    уровень персонажа, и рандом ±50%
     */
    protected function calculateDamageForCharacter(array $character): float
    {
        // Ищем event 'Hurricane'
        $event = $this->eventModel
            ->where('name_english', 'Hurricane')
            ->first();
        if (!$event) {
            return 0; // Не нашли => урона нет
        }

        $effectValue = $event['effect_value'] ?? 65;

        // Смотрим биом
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            // Нет биома => берём базу
            return $effectValue;
        }

        $danger     = $biome['danger_level']        ?? 5;
        $difficulty = $biome['survival_difficulty'] ?? 5;
        $charLevel  = $character['level']           ?? 1;

        // Факторы
        $levelFactor  = max(1, 100 - $charLevel) / 100.0;
        $biomeFactor  = ($danger + $difficulty) / 20.0;
        // randomFactor (0.5..1.5)
        $randomFactor = rand(50, 150) / 100.0;

        $damage = $effectValue
            * $levelFactor
            * $biomeFactor
            * $randomFactor;

        return round($damage, 2);
    }

    /**
     * Уведомляем персонажа, если он на базе,
     * что ураган не причинил вреда.
     */
    protected function notifySafeOnBase(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "🌪 *Ураган* пронёсся мимо...\n\n"
            . "Но ты на базе, так что стихия не смогла причинить тебе вред!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при уведомлении safeOnBase: " . $e->getMessage());
        }
    }

    /**
     * Уведомляем игрока о полученном уроне
     */
    protected function notifyCharacter(array $character, float $damage, float $newHealth)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $roundedDamage = round($damage, 2);
        $roundedHealth = round($newHealth, 2);

        $message  = "⚠️ *Ураган* налетел!\n\n";
        $message .= "Ты потерял ~ *{$roundedDamage}* единиц здоровья.\n";
        $message .= "Текущее здоровье: *{$roundedHealth}*\n\n";
        $message .= "_Скорее укройся на базе или прими меры по восстановлению!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Изображение, соответствующее урагану
        $photoPath = base_url('uploads/telegram/strong_winds_and_downpours_causing_destruction.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке уведомления hurricane: " . $e->getMessage());
        }
    }
}
