<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use DateTime;

class CancelGatherAction extends BaseAction
{
    protected $resourceModel;
    protected $characterResourceModel;
    protected $biomeModel;
    protected $mapModel;
    protected $eventModel;
    protected $activeEventModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->biomeModel = new BiomeModel();
        $this->mapModel = new MapModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();

    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
                ]);
        }

        $task = $this->getActiveGatherTask($character['id']);
        if (!$task) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Задача сбора ресурсов не найдена или уже завершена.',
            ]);
        }

        $this->cancelGatherTask($task['id']);
        $resources = $this->getAvailableResources($character);

        $startTime = new DateTime($task['start_time']);
        $now = new DateTime();
        $totalDuration = $task['max_duration'];
        $spentTime = $now->getTimestamp() - $startTime->getTimestamp();
        $spentMinutes = $spentTime / 60;

        if ($spentMinutes < $task['min_duration']) {
            $this->penalizeCharacter($character);
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
//            return $this->sendReply("Вы слишком рано закончили добычу ресурсов и ничего не смогли найти.");
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Вы слишком рано закончили добычу ресурсов и ничего не смогли найти.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        $foundResources = $this->calculateFoundResources($resources, $task, $spentMinutes, $character);

        $this->saveFoundResources($foundResources, $character, $task);

        return $this->sendResourcesFoundReply($foundResources, $character);
    }

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

    protected function cancelGatherTask($taskId)
    {
        // Обновляем статус задачи на 'interrupted'
        $updateResult = $this->characterTaskModel->update($taskId, [
            'status' => 'interrupted',
            'end_time' => date('Y-m-d H:i:s'), // Опционально, можно также обновить время окончания задачи на текущее
        ]);

        // Проверяем, было ли успешно обновление
        if (!$updateResult) {
            // В случае неудачи, можно записать ошибку в лог или обработать её каким-либо образом
            return false;
        }

        return true;
    }

    protected function penalizeCharacter($character)
    {
        // Устанавливаем величину штрафа
        $healthPenalty = 10; // Уменьшение здоровья на 10%
        $intellectPenalty = 0.05; // Уменьшение интеллекта на 0.05 единицы

        // Вычисляем новые значения характеристик
        $newHealth = max(1, $character['health'] - ($character['health'] * $healthPenalty)); // Убедимся, что здоровье не опустится ниже 0
        $newIntellect = $character['intellect'] - $intellectPenalty;

        // Обновляем характеристики персонажа в базе данных
        $updateResult = $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'intellect' => $newIntellect,
        ]);

        // Проверяем, было ли успешно обновление
        if (!$updateResult) {
            // В случае неудачи, можно записать ошибку в лог или обработать её каким-либо образом
            return false;
        }

        return true;
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

    protected function calculateFoundResources($resources, $task, $spentMinutes, $character)
    {
        $isLocustExodusActive = $this->isEventActive('LocustExodus');
        $locustExodusEffect = $isLocustExodusActive ? $this->eventModel->where('name_english', 'LocustExodus')->first()['effect_value'] : 0;

        // Проверка активности событий
        $isFishStockActive = $this->checkIfEventIsActive('FishStock');
        $isExoticFloweringActive = $this->checkIfEventIsActive('ExoticFlowering');
        $isBerryBoomActive = $this->checkIfEventIsActive('BerryBoom');
        $isDrynessActive = $this->isDrynessEventActive();

        // События
        $fishStockEvent = $this->eventModel->where('name_english', 'FishStock')->first();
        $exoticFloweringEvent = $this->eventModel->where('name_english', 'ExoticFlowering')->first();
        $berryBoomEvent = $this->eventModel->where('name_english', 'BerryBoom')->first();
        $drynessEvent = $isDrynessActive ? $this->eventModel->where('name_english', 'Dryness')->first() : null;

        $taskInfo = $this->taskModel->where('id', $task['task_id'])->first();
        $foundResources = [];
        $waterResourcesIds = [];

        foreach ($resources as $resource) {
            if ($this->isResourceCollectible($resource, $character)) {
                $resourceFactor = $this->getResourceFactor($resource, $task, $taskInfo);
                $characterFactor = $this->getCharacterFactor($character);

                $totalAmount = round($resourceFactor * $characterFactor * ($spentMinutes / max(1, $taskInfo['max_duration'] - $taskInfo['min_duration'])));

                if ($isLocustExodusActive) {
                    $totalAmount *= (1 - $locustExodusEffect / 100);
                }

                // Применяем эффекты событий
                if ($isFishStockActive && $resource['name'] == "Рыба") {
                    $totalAmount *= (1 + $fishStockEvent['effect_value'] / 100);
                }

                // Применение эффекта события "Экзотическое цветение", если условия выполнены
                if ($isExoticFloweringActive && $resource['name'] == "Цветы орхидей") {
                    $totalAmount = round($totalAmount * (1 + $exoticFloweringEvent['effect_value'] / 100));
                }elseif ($isExoticFloweringActive && $resource['name'] == "Ядовитые растения"){
                    $totalAmount = round($totalAmount * (1 + $exoticFloweringEvent['effect_value'] / 100));
                }elseif ($isExoticFloweringActive && $resource['name'] == "Мясо диких животных"){
                    $totalAmount = round($totalAmount * (1 + $exoticFloweringEvent['effect_value'] / 100));
                }

                // Применение эффекта события "Ягодный бум", если условия выполнены
                if ($isBerryBoomActive && $resource['name'] == "Ягоды") {
                    $totalAmount = round($totalAmount * (1 + $berryBoomEvent['effect_value'] / 100));
                }

                // Отмечаем ресурсы с типом 'water', если событие 'Засуха' активно
                if ($isDrynessActive && strpos($resource['type'], 'water') !== false) {
                    $waterResourcesIds[] = $resource['id'];
                }

                $foundResources[] = [
                    'resource_id' => $resource['id'],
                    'amount' => max(1, $totalAmount)
                ];

            }
        }

        // Корректируем количество водных ресурсов, если событие 'Засуха' активно
        if ($isDrynessActive && !empty($waterResourcesIds)) {
            $effectValue = (float)$drynessEvent['effect_value'] / 100;
            foreach ($foundResources as &$resource) {
                if (in_array($resource['resource_id'], $waterResourcesIds)) {
                    $resource['amount'] = round($resource['amount'] * (1 - $effectValue));
                }
            }
            unset($resource); // Отменяем ссылку на последний элемент
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
        if ($resource['rarity'] >= 2 && $resource['rarity'] <= 5) {
            $randomMultiplier1 = rand(101, 299) / 100;
            $resource['rarity'] *= $randomMultiplier1;
        } elseif ($resource['rarity'] >= 6 && $resource['rarity'] <= 9) {
            $randomMultiplier2 = rand(155, 399) / 100;
            $resource['rarity'] *= $randomMultiplier2;
        } elseif ($resource['rarity'] === 10) {
            $randomMultiplier3 = rand(255, 499) / 100;
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


    public function getActiveGatherTask($characterId)
    {
        // Убедитесь, что 'difficulty_level' извлекается вместе с другими данными
        $query = $this->characterTaskModel
            ->select('character_tasks.*, tasks.name as task_name, tasks.min_duration, tasks.max_duration, tasks.difficulty_level') // Добавьте 'tasks.difficulty_level'
            ->join('tasks', 'character_tasks.task_id = tasks.id', 'inner')
            ->where('character_tasks.character_id', $characterId)
            ->where('character_tasks.status', 'in_work')
            ->where('tasks.name', 'Gather');

        return $query->first();
    }

    protected function saveFoundResources($foundResources, $character, $task)
    {
        foreach ($foundResources as $resource) {
            // Проверка на существование ресурса для персонажа
            // В методе saveFoundResources
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


    protected function sendResourcesFoundReply($foundResources, $character)
    {
        // Начало формирования сообщения
        $messageText = "*Пришлось прерваться!* 😥\n\n";
        $messageText .= "Неотложные дела заставила меня 🏃‍♂️ домой.\n\n";

        // Добавление информации об изменении характеристик персонажа
        $messageText .= "\n😠😠😠\n\n";
        $messageText .= "💪 *Но я не сдаюсь!*\n";
        $messageText .= "*Завтра я вернусь и продолжу свое дело!🤓*\n\n";
        $messageText .= "*❤️ Сегодня я вернулся домой живым, а это уже победа!*\n\n";
        $messageText .= "*Не вешай нос! 💪😜*\n\n";
        // Дополнительные детали по изменению характеристик могут быть добавлены здесь

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/character_rushes_back_to_his_base.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $messageText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function enrichFoundResourcesWithData($foundResources) {
        $enrichedFoundResources = [];
        foreach ($foundResources as $resource) {
            $resourceDetails = $this->resourceModel->find($resource['resource_id']);
            if ($resourceDetails) {
                $enrichedResource = array_merge($resource, [
                    'name' => $resourceDetails['name'],
                    'price' => $resourceDetails['price'],
                    'rarity' => $resourceDetails['rarity'],
                    'level_required' => $resourceDetails['level_required'],
                    'type' => $resourceDetails['type'],
                ]);
                $enrichedFoundResources[] = $enrichedResource;
            }
        }
        return $enrichedFoundResources;
    }

    protected function updateCharacterStats($character, $foundResources, $task)
    {
        $enrichedFoundResources = $this->enrichFoundResourcesWithData($foundResources);
        $infoAboutTask = $this->taskModel->where('id', $task['task_id'])->first();

        // Пересмотренные базовые коэффициенты
        $maxHealth = 100;
        $baseExperienceGain = 0.0005; // Существенно снижаем базовый прирост опыта
        $baseHealthGainPerFood = 0.2; // Уменьшаем прирост здоровья за единицу еды
        $baseAttributeGainPerResource = 0.001; // Уменьшаем прирост атрибутов за каждый ресурс

        // Инициализация переменных прироста
        $experienceGain = 0;
        $healthGain = 0;
        $strengthGain = 0;
        $agilityGain = 0;
        $intellectGain = 0;

        foreach ($enrichedFoundResources as $resource) {
            $rarityFactor = (10 - $resource['rarity']) / 10; // Инвертируем редкость, чтобы редкие ресурсы давали больше опыта
            $taskDifficultyFactor = 1 + ($infoAboutTask['difficulty_level'] / 100); // Снижаем влияние сложности задачи

            // Расчет прироста опыта с учетом редкости и сложности задачи
            $experienceGain += ($baseExperienceGain * $rarityFactor * $taskDifficultyFactor) * $resource['amount'];

            // Расчет прироста здоровья (пример для 'food')
            if ($resource['type'] === 'food') {
                $healthGain += $baseHealthGainPerFood * $resource['amount'];
            }

            // Расчет прироста атрибутов с учетом редкости ресурса
            $attributeGain = $baseAttributeGainPerResource * $rarityFactor * $resource['amount'];
            $strengthGain += $attributeGain;
            $agilityGain += $attributeGain;
            $intellectGain += $attributeGain;
        }

        // Применение ограничений на максимальные значения
        $newHealth = min($character['health'] + $healthGain, $maxHealth);

        // Обновление данных персонажа с нормализацией прироста
        $updatedCharacterData = [
            'experience' => $character['experience'] + $experienceGain,
            'health' => $newHealth,
            'strength' => $character['strength'] + $strengthGain,
            'agility' => $character['agility'] + $agilityGain,
            'intellect' => $character['intellect'] + $intellectGain,
        ];

        $this->characterModel->update($character['id'], $updatedCharacterData);
    }

}
