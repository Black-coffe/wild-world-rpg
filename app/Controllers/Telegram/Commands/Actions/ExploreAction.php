<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Services\Player\PlayerDetectionService;

// Подключаем PlayerDetectionService

class ExploreAction extends BaseAction
{
    protected $mapModel;
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $playerDetectionService;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->mapModel              = new MapModel();
        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->playerDetectionService= new PlayerDetectionService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе или персонаж не создан.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        if (!$this->deductTiredness($character)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Выносливость слишком низкая (текущий уровень: {$character['tired']}), нужно отдохнуть!",
            ]);
        }

        // Проверка на параллельные задачи
        if (!$this->checkParallelExecutionAllowed($character['id'])) {
            $response = $this->prepareBlockedTaskResponse('cancelExploration');
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => $response['text'],
                'reply_markup' => $response['reply_markup'],
                'parse_mode' => 'Markdown',
            ]);
        }

        // Смотрим callbackData
        $callbackData = $this->callbackQuery->getData();
        // Например: 'explore' или 'explore_30'...

        if ($callbackData === 'explore') {
            // 1) Пока только предлагаем варианты времени
            $text = "Ты решил заняться исследованием местности.\n\n"
                . "⚠️ *Важно:* Если ты прервёшь задачу, _никаких_ результатов не получишь!\n\n"
                . "Выбери, на сколько минут/часов погрузиться в исследование:\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '10 мин',    'callback_data' => 'explore_10'],
                        ['text' => '30 мин',    'callback_data' => 'explore_30'],
                    ],
                    [
                        ['text' => '60 мин (1ч)', 'callback_data' => 'explore_60'],
                        ['text' => '120 мин(2ч)', 'callback_data' => 'explore_120'],
                    ],
                    [
                        ['text' => '6 часов',   'callback_data' => 'explore_360'],
                        ['text' => '12 часов',  'callback_data' => 'explore_720'],
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

        } else {
            // 2) Значит формат: 'explore_XXX'
            $parts = explode('_', $callbackData);
            if (count($parts) !== 2) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => 'Непонятный запрос на исследование.',
                ]);
            }

            $chosenMinutes = (int)$parts[1];
            // Создаём задачу 'ExploreTheArea', но на chosenMinutes

            $explorationTask = $this->taskModel->where('name', 'ExploreTheArea')->first();
            if (!$explorationTask) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => 'Задача "Изучить местность" не найдена.',
                ]);
            }

            // Запуск задачи
            $startTime = new \DateTime();

            // Учитываем Полярную ночь, если нужно
            $finalDuration = $this->adjustForPolarNightIfNeeded($character, $chosenMinutes);

            $endTime = (clone $startTime)->add(new \DateInterval('PT' . $finalDuration . 'M'));

            // Пишем в task_settings
            $taskSettings = ['chosen_minutes' => $chosenMinutes];

            $this->characterTaskModel->insert([
                'character_id'     => $character['id'],
                'telegram_user_id' => $user['id'],
                'task_id'          => $explorationTask['id'],
                'start_time'       => $startTime->format('Y-m-d H:i:s'),
                'end_time'         => $endTime->format('Y-m-d H:i:s'),
                'status'           => 'in_work',
                'task_settings'    => json_encode($taskSettings),
            ]);

            $interval  = $startTime->diff($endTime);
            $minutes   = $interval->days * 1440 + $interval->h * 60 + $interval->i;

            $text = "*Исследование местности началось!*\n\n"
                . "Ты планируешь уделить этому *{$minutes} минут*.\n\n"
                . "❗ Прерывание задачи = 0 результатов.\n\n"
                . "_Удачи, да будут открытия!_";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '❌ Прервать изучение', 'callback_data' => 'cancelExploration']
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            // Для антуража можно добавить картинку
            $imagePath = base_url('uploads/telegram/local_biome_research.png');

            $response = Request::sendPhoto([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

            // Вызываем сервис обнаружения игроков
            $this->playerDetectionService->detectNearbyPlayers($character['id']);

            return $response;
        }
    }

    /**
     * Если есть событие Полярная Ночь и игрок в затронутом биоме — увеличиваем время
     */
    protected function adjustForPolarNightIfNeeded(array|\App\Entities\CharacterEntity $character, int $chosenMinutes): int
    {
        $final = $chosenMinutes;

        // Смотрим, активна ли Полярная ночь
        if ($this->isPolarNightActive() && $this->isCharacterInAffectedBiome($character)) {
            $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
            if ($polarNightEvent) {
                $effectValue = (int)$polarNightEvent['effect_value'];
                $final = $chosenMinutes + (int) round($chosenMinutes * $effectValue / 100);
            }
        }

        return $final;
    }

    protected function isPolarNightActive()
    {
        $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
        if (!$polarNightEvent) {
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $polarNightEvent['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($activeEvent);
    }

    protected function isCharacterInAffectedBiome($character)
    {
        $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
        if (!$polarNightEvent || empty($polarNightEvent['biome_ids'])) {
            return false;
        }

        $affectedBiomes = json_decode($polarNightEvent['biome_ids'], true);
        $cell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$cell) {
            return false;
        }
        return in_array($cell['biome_id'], $affectedBiomes);
    }

    /**
     * Списываем усталость (пример)
     */
    protected function deductTiredness($character)
    {
        $level           = $character['level'];
        $currentTired    = $character['tired'];
        $tirednessNeeded = max(0.01, (1000 - $level) / 100);

        if ($currentTired < $tirednessNeeded) {
            return false;
        }

        $newTiredness = max(0.01, $currentTired - $tirednessNeeded);
        $newTiredness = round($newTiredness, 2);
        $this->characterModel->update($character['id'], ['tired' => $newTiredness]);
        return true;
    }
}
