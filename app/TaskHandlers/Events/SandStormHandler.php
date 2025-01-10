<?php

namespace App\TaskHandlers\Events;

use DateTime;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\TelegramUserModel;

class SandStormHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->biomeModel = new BiomeModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
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
        if (mt_rand(0, 100) >= 2) {
            return; // 98% шанс на то, что событие не будет обработано
        }

        $activeEvent = $this->isEventActive('Sandstorm');
        if (!$activeEvent) {
            return;  // Событие не включено, остановить обработку
        }

        if (!isset($activeEvent['biome_ids'])) {
            return;  // Нет биомов в списке доступных, остановить обработку
        }

        $affectedBiomes = json_decode($activeEvent['biome_ids'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;  // Ошибка декодирования JSON, остановка обработки
        }

        foreach ($affectedBiomes as $biomeId) {
            $charactersInBiome = $this->characterModel->where('biome_id', $biomeId)->findAll();
            foreach ($charactersInBiome as $character) {
                if (!$this->isCharacterBusyWithTasks($character['id'])) {
                    continue;  // Пропускаем не занятых персонажей
                }
                $this->applyFireEffects($character, $activeEvent);
            }
        }
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

    protected function isCharacterBusyWithTasks($characterId)
    {
        $db = db_connect(); // Получаем подключение к базе данных

        // Идентификаторы задач, связанных с событием (Изучение местности и Добыча ресурсов)
        $taskIds = $db->table('tasks')
            ->whereIn('name', ['ExploreTheArea', 'Gather'])
            ->select('id')
            ->get()
            ->getResultArray();

        // Преобразуем результат в массив ID задач
        $taskIdsArray = array_column($taskIds, 'id');

        // Проверяем, занят ли персонаж этими задачами
        $activeTasksCount = $db->table('character_tasks')
            ->whereIn('task_id', $taskIdsArray)
            ->where('character_id', $characterId)
            ->where('status', 'in_work')
            ->countAllResults();

        return $activeTasksCount > 0;
    }

    protected function applyFireEffects($character, $activeEvent)
    {
        if ($character['tired'] <= 0.01) {
            return; // Если усталость уже на минимальном уровне, дальнейшая обработка не требуется
        }

        $event = $this->eventModel->where('name_english', 'Sandstorm')->first();
        if (!$event) {
            return; // Если событие не найдено, прекращаем выполнение
        }

        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return; // Если биом не найден, прекращаем выполнение
        }

        // Рассчитываем урон усталости
        $effectValue = $event['effect_value'];
        $tiredDamage = $this->calculateTiredDamage($biome, $character['level'], $effectValue);

        // Применяем урон к усталости персонажа, учитывая минимальный порог в 0.01
        $newTiredness = max(0.01, $character['tired'] - $tiredDamage);
        $characterUpdateData = ['tired' => $newTiredness];

        // Рандомно выбираем атрибут для уменьшения
        $attributes = ['experience', 'strength', 'agility', 'intellect'];
        $attributeToReduce = $attributes[array_rand($attributes)];
        $currentAttributeValue = $character[$attributeToReduce];

        if ($currentAttributeValue > 0.01) {
            $characterUpdateData[$attributeToReduce] = max(0.01, $currentAttributeValue - 0.01);
        }

        $this->characterModel->update($character['id'], $characterUpdateData);

        $endTime = $this->formatEventEndTime($activeEvent);
        $this->notifyCharacterAboutFire($character, $tiredDamage, $attributeToReduce, $endTime);
    }

    protected function calculateTiredDamage($biome, $characterLevel, $effectValue)
    {
        $levelFactor = max(1, 100 - $characterLevel) / 100; // Меньше урона для высокоуровневых персонажей
        $biomeFactor = ($biome['danger_level'] + $biome['survival_difficulty']) / 20; // Влияние биома на урон
        $randomFactor = rand(50, 150) / 100; // Колебание урона
        return round($effectValue * $levelFactor * $biomeFactor * $randomFactor, 2); // Формула урона, округленная до двух десятичных знаков
    }

    protected function formatEventEndTime($isActiveEvent)
    {
        $endDateTime = new DateTime($isActiveEvent['end_time']);
        $currentDateTime = new DateTime();
        $interval = $currentDateTime->diff($endDateTime);

        $days = $interval->format('%a');
        $hours = $interval->format('%H');
        $minutes = $interval->format('%I');

        return "⏳ Событие закончится через: {$days} дн. {$hours} чс. {$minutes} мин.";
    }

    protected function notifyCharacterAboutFire($character, $damage, $attributeReduced, $endTime)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        // Сформировать сообщение
        $message = "🔥 *Внимание!* Ваш персонаж попал под воздействие *Песчаной бури*.\n\n";
        $message .= "🥱 Выносливость снизилась на: *{$damage}*.\n";
        $message .= "🔻 Уменьшение {$attributeReduced}: *0.01*.\n";
        $message .= "*{$endTime}*.\n\n";
        $message .= "🛑 Рекомендуется немедленно отойти на безопасное расстояние или вернуться на базу для избежания дальнейшего урона.";

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
        $photo = base_url('uploads/telegram/sand_storm.png'); // Необходимо указать реальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($photo),
            'caption' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}
