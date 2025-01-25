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

// Подключаем ваш сервис
use App\Services\Player\PlayerStateService;

class HurricaneHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $playerStateService;
    private   $telegram;

    public function __construct()
    {
        $this->characterModel    = new CharacterModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->biomeModel        = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализируем сервис проверок
        $this->playerStateService = new PlayerStateService();

        $this->initTelegram();
    }

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

    public function process()
    {
        // 1) Вероятность ~35%
        if (mt_rand(1, 100) > 35) {
            return; // ~65% случаев событие не обрабатывается
        }

        // 2) Проверяем, активно ли событие
        $activeEvent = $this->isEventActive('Hurricane');
        if (!$activeEvent) {
            return; // Событие неактивно
        }

        // 3) Получаем список персонажей, затронутых ураганом
        $affectedCharacters = $this->getAffectedCharacters();
        if (empty($affectedCharacters)) {
            return; // Никто не подходит
        }

        // 4) Выбираем одного случайного персонажа
        $selectedCharacterId = $affectedCharacters[array_rand($affectedCharacters)];
        $this->applyHurricaneEffects($selectedCharacterId);
    }

    protected function isEventActive(string $eventNameEnglish)
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$eventInfo || empty($eventInfo['biome_ids'])) {
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Добавим нужные поля, если хотим (biome_ids, effect_value)
            $activeEvent['biome_ids']   = $eventInfo['biome_ids'];
            $activeEvent['effect_value'] = $eventInfo['effect_value'];
            return $activeEvent;
        }

        return false;
    }

    protected function getAffectedCharacters()
    {
        // Ищем, какие биомы охватывает ураган
        $active = $this->isEventActive('Hurricane');
        if (!$active) {
            return [];
        }

        $biomeIds = json_decode($active['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return [];
        }

        // Подключение к БД
        $db = db_connect();

        // Идентификаторы задач (ExploreTheArea, Gather)
        $taskIds = $db->table('tasks')
            ->whereIn('name', ['ExploreTheArea', 'Gather'])
            ->select('id')
            ->get()
            ->getResultArray();
        $taskIdsArray = array_column($taskIds, 'id');

        // Находим персонажей, у которых есть активные (in_work) задачи из списка taskIds
        // и которые находятся в затронутых биомах
        $characters = $db->table('character_tasks')
            ->select('character_id')
            ->whereIn('task_id', $taskIdsArray)
            ->where('status', 'in_work')
            ->join('characters', 'characters.id = character_tasks.character_id')
            ->whereIn('biome_id', $biomeIds)
            ->groupBy('character_id')
            ->get()
            ->getResultArray();

        // Возвращаем список ID персонажей
        return array_column($characters, 'character_id');
    }

    protected function applyHurricaneEffects(int $characterId)
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return;
        }

        // 1) Если персонаж на базе - урона нет, отправляем уведомление
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            $this->notifySafeOnBase($character);
            return;
        }

        // 2) Проверяем уровень урона (50% или 100%)
        $fullDamage = $this->calculateDamageForCharacter($character);

        // Если игрок НЕ собирает и НЕ изучает => 50% от fullDamage
        $isGather = $this->playerStateService->isGathering($characterId);
        $isExplore = $this->playerStateService->isExploring($characterId);

        $damage = $fullDamage;
        if (!$isGather && !$isExplore) {
            $damage = $fullDamage * 0.50; // Половина
        }
        $damage = round($damage, 2);

        // 3) Применяем урон (не опускаем здоровье ниже 1)
        $newHealth = max(0.01, $character['health'] - $damage);
        $this->characterModel->update($characterId, ['health' => $newHealth]);

        // 4) Уведомляем
        $this->notifyCharacter($character, $damage, $newHealth);
    }

    /**
     * Примерная формула расчета урона (берём effect_value=65 + факторы)
     */
    protected function calculateDamageForCharacter(array $character): float
    {
        $event = $this->eventModel->where('name_english', 'Hurricane')->first();
        if (!$event) {
            // Если нет данных, возвращаем базовое
            return 0;
        }

        $effectValue = $event['effect_value'] ?? 65;

        // Допустим, делаем некую формулу
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            // нет биома -> базовый
            return (float)$effectValue;
        }

        $danger     = $biome['danger_level']        ?? 5;
        $difficulty = $biome['survival_difficulty'] ?? 5;
        $charLevel  = $character['level']           ?? 1;

        $levelFactor = max(1, 100 - $charLevel) / 100.0;
        $biomeFactor = ($danger + $difficulty) / 20.0;
        $randomFactor= rand(50, 150) / 100.0; // +/-50%

        $damage = $effectValue * $levelFactor * $biomeFactor * $randomFactor;
        return round($damage, 2);
    }

    /**
     * Если персонаж на базе — отправляем уведомление, что ураган не повредил.
     */
    protected function notifySafeOnBase(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "🌪 *Ураган* промчался мимо...\n\n"
            . "Но ты находишься в своей базе-крепости, и стихия не смогла тебе навредить!";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке safeOnBase: " . $e->getMessage());
        }
    }

    /**
     * Уведомление о полученном уроне
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
        $message .= "Ты потерял примерно *{$roundedDamage}* единиц здоровья.\n";
        $message .= "Текущее здоровье: *{$roundedHealth}*\n\n";
        $message .= "_Совет: вернись на базу или прими меры для восстановления!_\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        // Допустим, есть какая-то картинка
        $photoPath = base_url('uploads/telegram/strong_winds_and_downpours_causing_destruction.png');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photoPath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyCharacter: " . $e->getMessage());
        }
    }
}
