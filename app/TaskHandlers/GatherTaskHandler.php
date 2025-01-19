<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use CodeIgniter\Controller;
use DateTime;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use App\Libraries\BiomeResourceModifier;
use App\Libraries\ToolManager;

class GatherTaskHandler extends Controller
{
    protected $characterModel;
    protected $characterResourceModel;
    protected $characterTaskModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $telegramUserModel;
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $biomeResourceModifier;
    protected $toolManager;

    private $telegram;

    public function __construct()
    {
        // Подключение моделей и инициализация Telegram
        $this->characterModel = new CharacterModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->taskModel = new TaskModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->biomeResourceModifier = new BiomeResourceModifier();
        $this->toolManager = new ToolManager();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function handle($task)
    {
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', "Character not found for task ID: {$task['id']}");
            return;
        }

        $locustExodusActive = $this->isEventActive('LocustExodus');
        $locustExodusEffect = 0;
        if ($locustExodusActive) {
            $locustExodusEvent = $this->eventModel->where('name_english', 'LocustExodus')->first();
            $locustExodusEffect = (float)$locustExodusEvent['effect_value'];
        }

        $resources = $this->getAvailableResources($character);
        $spentMinutes = $this->calculateSpentMinutes($task['start_time'], $task['end_time']);
        $foundResources = $this->calculateFoundResources($resources, $task, $spentMinutes, $character, $locustExodusEffect, $locustExodusActive);

        // Применение модификаций ресурсов в зависимости от биома
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        $foundResources = $this->biomeResourceModifier->modifyResourcesByBiome($currentCell['biome_id'], $foundResources);

        $this->saveFoundResources($foundResources, $character, $task);
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        $this->sendResourcesFoundReply($foundResources, $character);
    }

    protected function getBiomeId($character)
    {
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        return $currentCell['biome_id'] ?? null;
    }

    /**
     * Checks if a given event is currently active.
     *
     * @param string $eventNameEnglish The English name of the event to check.
     * @return bool Returns true if the event is active, false otherwise.
     */
    protected function isEventActive($eventNameEnglish) {
        $event = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if ($event) {
            $activeEvent = $this->activeEventModel->where([
                'event_id' => $event['event_id'],
                'status' => 'active'
            ])->first();
            return !empty($activeEvent);
        }
        return false;
    }

    // Этот метод заполнен аналогично методу в CancelGatherAction
    protected function getAvailableResources($character)
    {
        // Получаем текущую локацию персонажа
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', "Location for character {$character['id']} not found.");
            return []; // Возвращаем пустой массив, если локация не найдена
        }

        // Получаем биом текущей локации
        $biome = $this->biomeModel->find($currentCell['biome_id']);
        if (!$biome) {
            log_message('error', "Biome for cell {$currentCell['cell_number']} not found.");
            return []; // Возвращаем пустой массив, если биом не найден
        }

        // Получаем ресурсы, доступные в этом биоме, учитывая уровень персонажа
        $resources = $this->resourceModel
            ->like('biome_id', (string)$biome['id'], 'both') // Используем метод like для поиска
            ->where('level_required <=', $character['level'])
            ->findAll();

        if (empty($resources)) {
            log_message('info', "No resources found for biome {$biome['id']} and character level {$character['level']}.");
        }

        return $resources;
    }

    // Этот метод аналогичен методу в CancelGatherAction, но адаптирован под конец задачи
    protected function calculateSpentMinutes($startTime, $endTime) {
        // Преобразуем строки времени в объекты DateTime
        $startDateTime = new DateTime($startTime);
        $endDateTime = new DateTime($endTime);

        // Рассчитываем разницу между конечным и начальным временем
        $interval = $startDateTime->diff($endDateTime);

        // Преобразуем интервал в минуты
        // Учитывая, что интервал может быть представлен в днях, часах и минутах, преобразуем все в минуты
        $spentMinutes = $interval->days * 24 * 60; // Преобразуем дни в минуты
        $spentMinutes += $interval->h * 60;        // Добавляем часы, преобразованные в минуты
        $spentMinutes += $interval->i;             // Добавляем минуты

        return $spentMinutes;
    }

    protected function isDrynessEventActive() {
        $drynessEvent = $this->eventModel->where('name_english', 'Dryness')->first();
        if ($drynessEvent) {
            $activeDrynessEvent = $this->activeEventModel->where([
                'event_id' => $drynessEvent['event_id'],
                'status' => 'active'
            ])->first();

            return !empty($activeDrynessEvent);
        }

        return false;
    }

    protected function updateCharacterStats($character, $foundResources, $task)
    {
        // Начальные значения для прироста
        $experienceGain = 0;
        $healthGain = 0;
        $strengthGain = 0;
        $agilityGain = 0;
        $intellectGain = 0;

        // Предположим, что каждый ресурс приносит определенное количество опыта
        foreach ($foundResources as $resource) {
            $strengthGain += 0.006; // Например, каждый ресурс добавляет 0.1 к силе
            $agilityGain += 0.001; // Например, каждый ресурс добавляет 0.1 к ловкости
            $intellectGain += 0.001; // Например, каждый ресурс добавляет 0.1 к интеллекту
            $healthGain += 0.005; // и 0.05 к здоровью
        }

        // Обновление данных персонажа
        $updatedData = [
            'experience' => $character['experience'] + $experienceGain,
            'health' => min($character['health'] + $healthGain, 100), // Предполагаем, что максимум здоровья - 100
            'strength' => $character['strength'] + $strengthGain,
            'agility' => $character['agility'] + $agilityGain,
            'intellect' => $character['intellect'] + $intellectGain,
        ];

        // Сохранение обновленных данных в базу
        $this->characterModel->update($character['id'], $updatedData);
    }

    protected function calculateFoundResources($resources, $task, $spentMinutes, $character, $locustExodusEffect, $locustExodusActive)
    {
        $isFishStockActive = $this->checkIfEventIsActive('FishStock');
        $isExoticFloweringActive = $this->checkIfEventIsActive('ExoticFlowering');
        $isBerryBoomActive = $this->checkIfEventIsActive('BerryBoom');
        $isDrynessActive = $this->isDrynessEventActive();

        $fishStockEvent = $isFishStockActive ? $this->eventModel->where('name_english', 'FishStock')->first() : null;
        $exoticFloweringEvent = $isExoticFloweringActive ? $this->eventModel->where('name_english', 'ExoticFlowering')->first() : null;
        $berryBoomEvent = $isBerryBoomActive ? $this->eventModel->where('name_english', 'BerryBoom')->first() : null;
        $drynessEvent = $isDrynessActive ? $this->eventModel->where('name_english', 'Dryness')->first() : null;

        $taskInfo = $this->taskModel->where('id', $task['task_id'])->first();
        $foundResources = [];
        $waterResourcesIds = [];

        foreach ($resources as $resource) {
            if ($this->isResourceCollectible($resource, $character)) {
                $toolEffect = $this->toolManager->applyToolEffect($resource, $character);
                $resourceFactor = $this->getResourceFactor($resource, $task, $taskInfo);
                $characterFactor = $this->getCharacterFactor($character);
                $levelMultiplier = $this->getLevelMultiplier($character['level']);

                $totalAmount = round($resourceFactor * $characterFactor * $toolEffect * ($spentMinutes / max(1, $taskInfo['max_duration'] - $taskInfo['min_duration'])) * $levelMultiplier);

                if ($isFishStockActive && $resource['name'] == "Рыба") {
                    $totalAmount *= (1 + $fishStockEvent['effect_value'] / 100);
                }

                if ($isExoticFloweringActive && $resource['name'] == "Цветы орхидей") {
                    $totalAmount *= (1 + $exoticFloweringEvent['effect_value'] / 100);
                }

                if ($isBerryBoomActive && $resource['name'] == "Ягоды") {
                    $totalAmount *= (1 + $berryBoomEvent['effect_value'] / 100);
                }

                if ($isDrynessActive && strpos($resource['type'], 'water') !== false) {
                    $waterResourcesIds[] = $resource['id'];
                }

                if ($locustExodusActive) {
                    $totalAmount *= (1 - $locustExodusEffect / 100);
                }

                if ($totalAmount > 0) {
                    $foundResources[] = [
                        'resource_id' => $resource['id'],
                        'amount' => max(1, $totalAmount)
                    ];
                }
            }
        }

        if ($isDrynessActive && !empty($waterResourcesIds)) {
            foreach ($foundResources as &$resource) {
                if (in_array($resource['resource_id'], $waterResourcesIds)) {
                    $resource['amount'] = round($resource['amount'] * (1 - $drynessEvent['effect_value'] / 100));
                }
            }
            unset($resource);
        }

        return $foundResources;
    }

    protected function checkIfEventIsActive($eventNameEnglish)
    {
        $activeEvent = $this->eventModel
            ->join('active_events', 'events.event_id = active_events.event_id')
            ->where('events.name_english', $eventNameEnglish)
            ->where('active_events.status', 'active')
            ->first();

        return !empty($activeEvent);
    }

    protected function isResourceCollectible($resource, $character)
    {
        // Код остается без изменений
        return isset($resource['rarity'], $resource['level_required']) &&
            $resource['level_required'] <= $character['level'];
    }

    protected function getResourceFactor($resource, $task, $taskInfo)
    {
        // Адаптируем значение редкости в зависимости от заданного диапазона
        if ($resource['rarity'] >= 2 && $resource['rarity'] <= 3) {
            $randomMultiplier1 = rand(101, 299) / 70;
            $resource['rarity'] *= $randomMultiplier1;
        } elseif ($resource['rarity'] >= 4 && $resource['rarity'] <= 5) {
            $randomMultiplier2 = rand(200, 399) / 65;
            $resource['rarity'] *= $randomMultiplier2;
        } elseif ($resource['rarity'] >= 6 && $resource['rarity'] <= 9) {
            $randomMultiplier4 = rand(400, 699) / 60;
            $resource['rarity'] *= $randomMultiplier4;
        } elseif ($resource['rarity'] == 10) {
            $randomMultiplier3 = rand(700, 900) / 55;
            $resource['rarity'] *= $randomMultiplier3;
        }

        // Рассчитываем фактор редкости
        $rarityFactor = $resource['rarity'];
        $difficultyLevel = max(1, $taskInfo['difficulty_level']);

        // Рассчитываем корректировку на основе сложности задачи
        $difficultyAdjustment = 1 / (1 + $difficultyLevel / 10);

        // Возвращаем конечный фактор ресурса
        return $rarityFactor * $difficultyAdjustment;
    }

    protected function getCharacterFactor($character)
    {
        return 1 + 0.05 * ($character['strength'] + $character['agility'] + $character['intellect'] + $character['level']);
    }

    // Метод сохранения найденных ресурсов и обновления статистики персонажа
    protected function saveFoundResources($foundResources, $character, $task)
    {
        foreach ($foundResources as $resource) {
            // Проверка на существование ресурса для персонажа
            $existingResource = $this->characterResourceModel->where([
                'id_characters' => $character['id'],
                'id_resources' => $resource['resource_id'],
            ])->first();

            if ($existingResource) {
                $newQuantity = $existingResource['quantity'] + $resource['amount'];
                $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
            } else {
                $this->characterResourceModel->insert([
                    'id_characters' => $character['id'],
                    'id_resources' => $resource['resource_id'],
                    'id_telegram_users' => $character['telegram_user_id'],
                    'quantity' => $resource['amount'],
                ]);
            }
        }
        // После сохранения ресурсов обновляем характеристики персонажа
        $this->updateCharacterStats($character, $foundResources, $task);
    }

    // Метод отправки сообщения пользователю о найденных ресурсах и обновлении статистики
    protected function sendResourcesFoundReply($foundResources, $character) {
        $chatId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $messageText = "<b>Успешная добыча ресурсов!</b>\n\n";
        foreach ($foundResources as $resource) {
            $resourceData = $this->resourceModel->find($resource['resource_id']);
            $messageText .= "📦 <b>{$resourceData['name']}:</b> {$resource['amount']}\n";
        }

        if (empty($foundResources)) {
            $messageText .= "Не удалось найти ресурсы.\n";
        }

        $messageText .= "\n<b>Твои усилия были вознаграждены!</b>\n\n";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile(base_url('uploads/telegram/loot_resources_in_the_box.png')),
                'caption' => $messageText,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Failed to send message: " . $e->getMessage());
        }
    }

//    protected function getLevelMultiplier($level)
//    {
//        // Каждые 10 уровней дают прирост на 1%
//        return 1 + floor($level / 10) * 0.01;
//    }

    protected function getLevelMultiplier($level)
    {
        // Каждые 10 уровней добавляют коэффициент снижения на 10%
        return 1 - (0.09 * floor($level / 100));
    }
}

