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

class FlashForestFireHandler extends Controller
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
        if (mt_rand(0, 100) >= 20) {
            return; // 80% шанс на то, что событие не будет обработано
        }

        $activeEvent = $this->isEventActive('FlashForestFire');
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
        // Проверяем, что текущее здоровье персонажа больше минимального порога
        if ($character['health'] <= 0.01) {
            return; // Если здоровье меньше или равно минимальному порогу, прекращаем дальнейшую обработку
        }

        // Получаем информацию о текущем событии лесного пожара
        $event = $this->eventModel->where('name_english', 'FlashForestFire')->first();
        if (!$event) {
            return; // Если событие лесного пожара не найдено, прекращаем выполнение
        }

        // Получаем информацию о биоме, в котором находится персонаж
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return; // Если биом не найден, прекращаем выполнение
        }

        // Рассчитываем урон, который будет нанесен персонажу из-за лесного пожара
        $effectValue = $event['effect_value'];
        $damage = $this->calculateFireDamage($biome, $character['level'], $effectValue);

        // Применяем урон к здоровью персонажа, учитывая минимальный порог в 0.01
        $newHealth = max(0.01, $character['health'] - $damage);
        $this->characterModel->update($character['id'], ['health' => $newHealth]);

        $endTime = $this->formatEventEndTime($activeEvent); // Форматирование времени окончания события
        $this->notifyCharacterAboutFire($character, $damage, $endTime); // Передайте все три параметра
    }

    protected function calculateFireDamage($biome, $characterLevel, $effectValue)
    {
        // Предположим, что формула урона будет зависеть от эффекта события, уровня персонажа и специфики биома
        $levelFactor = max(1, 100 - $characterLevel) / 100; // Чем выше уровень, тем меньше урона получит персонаж
        $biomeFactor = ($biome['danger_level'] + $biome['survival_difficulty']) / 20; // Сложность биома влияет на урон
        $damage = $effectValue * $levelFactor * $biomeFactor; // Базовая формула урона

        // Внедряем элемент случайности в расчет урона
        $randomFactor = rand(50, 150) / 100; // Колебание в пределах +/- 50%
        $damage *= $randomFactor;

        // Округляем урон до двух десятичных знаков
        return round($damage, 2);
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


    protected function notifyCharacterAboutFire($character, $damage, $endTime)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        // Сформировать сообщение
        $message = "🔥 *Внимание!* Ваш персонаж попал под воздействие *лесного пожара*.\n\n";
        $message .= "🔥 Полученный урон: *{$damage}* HP.\n";
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
        $photo = base_url('uploads/telegram/huge_forest_fires.png'); // Необходимо указать реальный путь к изображению

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
