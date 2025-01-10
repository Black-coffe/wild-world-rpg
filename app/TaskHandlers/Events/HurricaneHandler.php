<?php
namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{CharacterModel, EventModel, ActiveEventModel, BiomeModel, TelegramUserModel};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class HurricaneHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $biomeModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->biomeModel = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->initTelegram();
    }

    private function initTelegram()
    {
        $API_KEY = getenv('telegram.API_KEY');
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
        if (mt_rand(0, 100) >= 20) {
            return; // 80% шанс на то, что событие не будет обработано
        }

        if (!$this->isEventActive('Hurricane')) {
            return;
        }

        $affectedCharacters = $this->getAffectedCharacters();
        $selectedCharacter = $affectedCharacters[array_rand($affectedCharacters)];
        $this->applyHurricaneEffects($selectedCharacter);
    }

    protected function isEventActive($eventNameEnglish)
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();

        if (!$eventInfo || empty($eventInfo['biome_ids'])) {
            return false;  // Event not active or malformed data
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Include biome_ids in the return to ensure downstream functions have access
            $activeEvent['biome_ids'] = $eventInfo['biome_ids'];
            return $activeEvent;  // Return the active event data including biome_ids
        }

        return false;  // Event is not active
    }

    protected function getAffectedCharacters()
    {
        $activeBiomes = json_decode($this->isEventActive('Hurricane')['biome_ids'], true);

        $db = db_connect(); // Получаем подключение к базе данных
        $taskIds = $db->table('tasks')
            ->whereIn('name', ['ExploreTheArea', 'Gather'])
            ->select('id')
            ->get()
            ->getResultArray();
        $taskIdsArray = array_column($taskIds, 'id');

        $characters = $db->table('character_tasks')
            ->select('character_id')
            ->whereIn('task_id', $taskIdsArray)
            ->where('status', 'in_work')
            ->join('characters', 'characters.id = character_tasks.character_id')
            ->whereIn('biome_id', $activeBiomes)
            ->groupBy('character_id')
            ->get()
            ->getResultArray();

        return array_column($characters, 'character_id');
    }

    protected function applyHurricaneEffects($characterId)
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return; // Если персонаж не найден, прекращаем выполнение
        }

        $biome = $this->biomeModel->find($character['biome_id']);
        $event = $this->eventModel->where('name_english', 'Hurricane')->first();

        $damage = $this->calculateDamage($biome, $character, $event['effect_value']);
        $newHealth = max(0.01, $character['health'] - $damage); // Удерживаем здоровье не ниже 0.01

        $this->characterModel->update($characterId, ['health' => $newHealth]);
        $this->notifyCharacter($character, $damage);
    }

    protected function calculateDamage($biome, $character, $effectValue)
    {
        $levelFactor = max(1, 100 - $character['level']) / 100; // Меньше урона для высокоуровневых персонажей
        $biomeFactor = ($biome['danger_level'] + $biome['survival_difficulty']) / 20; // Влияние биома на урон
        $randomFactor = rand(50, 150) / 100; // Колебания урона
        return $effectValue * $levelFactor * $biomeFactor * $randomFactor; // Формула урона
    }

    protected function notifyCharacter($character, $damage)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Если телеграм-пользователь не найден, выходим из метода
        }
        $roundedDamage = round($damage, 2); // Округление урона до двух десятичных знаков
        $newHealth = round($character['health'], 2); // Также округляем текущее здоровье до двух десятичных знаков
        $chatId = $telegramUserId['telegram_id']; // ID чата пользователя в Telegram

        // Сообщение пользователю
        $message = "⚠️ *Внимание*, во время события: ➡️ *Ураган.*\n\n";
        $message .= "ℹ️ *Вы потеряли:*\n";
        $message .= "💖 *{$roundedDamage}* _Единиц здоровья_\n\n";
        $message .= "💖 *Сейчас у вас: {$newHealth}* _Единиц здоровья_\n\n";
        $message .= "_Вам нужно вернуться на базу или использовать аптечку во избежание смерти!_ ☠️\n\n";
        $message .= "Посмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        // Путь к изображению для лесного пожара
        $photo = base_url('uploads/telegram/strong_winds_and_downpours_causing_destruction.png'); // Необходимо указать реальный путь к изображению

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
