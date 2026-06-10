<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;

class GatherAction extends BaseAction
{
    protected $mapModel;
    protected $biomeModel;
    protected $resourcesModel;
    protected $eventModel;
    protected $activeEventModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourcesModel = new ResourceModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
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

        // Проверка параллельных задач
        if (!$this->checkParallelExecutionAllowed($character['id'])) {
            $response = $this->prepareBlockedTaskResponse('cancelGather');
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => $response['text'],
                'reply_markup' => $response['reply_markup'],
                'parse_mode' => 'Markdown',
            ]);
        }

        // Узнаём, с каким колбеком мы зашли в этот класс:
        $callbackData = $this->callbackQuery->getData(); // например 'gather' или 'gather_30'

        if ($callbackData === 'gather') {
            // 1) Пока только предлагаем выбрать время.
            // Выводим предупреждение и набор кнопок:
            $text = "Ты решил заняться добычей ресурсов.\n\n"
                . "⚠️ *Внимание!* При прерывании задачи _никакие_ ресурсы не будут получены! "
                . "Серьёзно отнесись к выбору времени!\n\n"
                . "🕑 Базовый расчёт идёт за 10 минут,\n"
                . "но если выберешь больше — лут будет больше (и риск тоже!).";

            // Кнопки: 10 мин, 30 мин, 60 мин, 120 мин, 360 мин, 720 мин
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '10 минут',   'callback_data' => 'gather_10'],
                        ['text' => '30 минут',   'callback_data' => 'gather_30'],
                    ],
                    [
                        ['text' => '1 час',      'callback_data' => 'gather_60'],
                        ['text' => '2 часа',     'callback_data' => 'gather_120'],
                    ],
                    [
                        ['text' => '6 часов',    'callback_data' => 'gather_360'],
                        ['text' => '12 часов',   'callback_data' => 'gather_720'],
                    ],
                ]
            ];

            // ADR-104 Ф2 — быстрый первый цикл: новичку (level ≤ max) добавляем быструю
            // опцию добычи поверх стандартных, чтобы первый ресурс пришёл за минуты,
            // а не за 10 мин. dormant под onboarding.fast_start.enabled.
            $fastMinutes = $this->fastStartGatherMinutes($character);
            if ($fastMinutes !== null) {
                array_unshift($keyboard['inline_keyboard'], [
                    ['text' => "⚡ {$fastMinutes} мин (быстрый старт)", 'callback_data' => "gather_{$fastMinutes}"],
                ]);
                $text .= "\n\n⚡ *Быстрый старт:* для первых шагов доступна короткая добыча — "
                    . "лута меньше, зато результат сразу.";
            }

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

        } else {
            // 2) Похоже, пользователь выбрал один из вариантов 'gather_XX'
            // Извлекаем из колбека, сколько минут хотел
            // Например, gather_30 => 30 минут
            $parts = explode('_', $callbackData);
            if (count($parts) !== 2) {
                // Некорректный колбек, просто завершим
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'Непонятный запрос добычи ресурсов.',
                ]);
            }

            $chosenMinutes = (int) $parts[1]; // 10, 30, 60, 120, 360, 720

            // v0.51.28 fix: deductTiredness тільки тут (на реальному старті задачі),
            // не на показ меню вибору часу. Раніше — double-deduct (5 stamina × 2:
            // натиснення `gather` + натиснення `gather_30`). Reported у Bugs-info.
            if (!$this->deductTiredness($character)) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => "Твоя выносливость слишком низкая (текущий уровень: {$character['tired']}), нужно отдохнуть!",
                ]);
            }

            // Дальше идёт обычная логика: проверяем локацию, биом, ресурсы, создаём задачу
            $cell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
            if (!$cell) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'Локация персонажа не найдена.',
                ]);
            }
            $biome = $this->biomeModel->find($cell['biome_id']);
            if (!$biome) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'Биом для локации не найден.',
                ]);
            }

            $biomeId   = (int) $biome['id'];
            $resources = $this->resourcesModel
                ->where("FIND_IN_SET({$biomeId}, biome_id) > 0", null, false)
                ->where('level_required <=', $character['level'])
                ->findAll();

            if (empty($resources)) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'В данном биоме нет доступных ресурсов для сбора.',
                ]);
            }

            $gatherTask = $this->taskModel->where('name', 'Gather')->first();
            if (!$gatherTask) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'Задача "Сбор ресурсов" не найдена.',
                ]);
            }

            // Создаём задачу, но учитываем выбранные $chosenMinutes
            // Вместо $gatherTask['max_duration'] используем $chosenMinutes
            // (или присваиваем $gatherTask['max_duration'] = $chosenMinutes перед вызовом)
            $originalMax = $gatherTask['max_duration'];
            $gatherTask['max_duration'] = $chosenMinutes; // переопределяем

            // Теперь создаём задачу
            $taskID = $this->createGatherTask($character, $user, $gatherTask);

            // Восстанавливаем исходное значение, если нужно
            $gatherTask['max_duration'] = $originalMax;

            // И отправляем ответ
            return $this->sendGatherResponse($character, $biome, $gatherTask, $taskID);
        }
    }

    /**
     * ADR-104 Ф2 — длительность быстрой опции добычи для новичка (или null, если
     * не положено). Читает GameSettings и делегирует чистой decideFastGatherMinutes.
     *
     * $character приходит из getUserAndCharacter() как CharacterEntity (ArrayAccess),
     * НЕ обязательно array — поэтому union-тип (урок feedback_entity_migration_grep_synonyms).
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    protected function fastStartGatherMinutes(array|\App\Entities\CharacterEntity $character): ?int
    {
        $gs       = new \App\Services\GameSettings\GameSettingsService();
        $levelRaw = $character['level'] ?? null;

        return self::decideFastGatherMinutes(
            (bool) $gs->get('onboarding.fast_start.enabled', false),
            is_numeric($levelRaw) ? (int) $levelRaw : 999,
            (int) $gs->get('onboarding.fast_start.max_level', 3),
            (int) $gs->get('onboarding.fast_start.gather_minutes', 2),
        );
    }

    /**
     * ADR-104 Ф2 — чистое решение: показать ли быструю опцию и на сколько минут.
     * dormant (enabled=false) / ветеран (level > max) / minutes < 1 → null.
     */
    public static function decideFastGatherMinutes(bool $enabled, int $level, int $maxLevel, int $minutes): ?int
    {
        if (! $enabled || $level > $maxLevel || $minutes < 1) {
            return null;
        }

        return $minutes;
    }

    protected function createGatherTask($character, $user, $gatherTask)
    {
        $startTime = new \DateTime();

        $playerChosenMinutes = $gatherTask['max_duration'];

        // Если НЕ хотим полярную ночь влиять - просто:
        $maxDuration = $playerChosenMinutes;

        // Дальше считаем endTime
        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $maxDuration . 'M'));

        // Сохранение задачи
        $this->characterTaskModel->save([
            'character_id'      => $character['id'],
            'telegram_user_id'  => $user['id'],
            'task_id'           => $gatherTask['id'],
            'start_time'        => $startTime->format('Y-m-d H:i:s'),
            'end_time'          => $endTime->format('Y-m-d H:i:s'),
            'status'            => 'in_work',
        ]);

        return $this->characterTaskModel->insertID();
    }

    protected function sendGatherResponse($character, $biome, $gatherTask, $taskID)
    {
        $activeTask = $this->characterTaskModel->where('id', $taskID)->first();

        // Преобразование строк с датами в объекты DateTime
        $startTime = new \DateTime($activeTask['start_time']);
        $endTime = new \DateTime($activeTask['end_time']);

        // Вычисление интервала
        $interval = $startTime->diff($endTime);
        $totalMinutes = ($interval->days * 1440) + ($interval->h * 60) + $interval->i;

        $timeLeftStr = $totalMinutes . ' мин.'; // Строка с общим количеством минут

        // Остальная часть метода остается без изменений
        $text = "<b>🌱 Сбор ресурсов начался!</b>\n\n" .
            "<b>В биоме: " . htmlspecialchars($biome['name']) . "</b>.\n\n" .
            "⏳ <b>Время на сбор ресурсов:</b> " . htmlspecialchars($timeLeftStr) . "\n\n" .
            "<b>Завершение в:</b> " . $endTime->format('H:i') . "\n\n" .
            "🚀 <b>В любой момент можно остановить поиски.</b> Помните, продолжительность вашего пребывания влияет на количество и уникальность находок.\n\n" .
            "🏹 <b>А так же время может повлиять на встречу с врагом или дикими животными!</b>\n\n" .
            "🍀 <b>Желаем удачи!</b> Пусть фортуна будет на вашей стороне!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Прервать добычу', 'callback_data' => 'cancelGather']
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/is_sent_to_extract_the_resources.png');

        // Ответ на колбек-запрос
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function getCorrectedDuration($character, $gatherTask)
    {
        // Аналогичный расчет корректировки продолжительности, который вы используете в createGatherTask
        $durationReduction = $this->calculateDurationReduction($character);
        $maxDuration = max(0, $gatherTask['max_duration'] - $durationReduction);
        if ($this->isPolarNightActive() && $this->isCharacterInAffectedBiome($character)) {
            $maxDuration = $this->adjustDurationForPolarNight($maxDuration);
        }

        return $maxDuration;
    }


    protected function isPolarNightActive() {
        $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
        if (!$polarNightEvent) {
            return false; // Событие "Полярная ночь" не найдено
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $polarNightEvent['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($activeEvent);
    }

    protected function isCharacterInAffectedBiome($character) {
        $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
        if (!$polarNightEvent || empty($polarNightEvent['biome_ids'])) {
            return false; // Событие не найдено или не указаны биомы
        }

        $affectedBiomes = json_decode($polarNightEvent['biome_ids'], true);
        $characterBiomeId = $this->mapModel->where('cell_number', $character['cell_number'])->first()['biome_id'];

        return in_array($characterBiomeId, $affectedBiomes);
    }

    protected function adjustDurationForPolarNight($duration) {
        $polarNightEvent = $this->eventModel->where('name_english', 'PolarNight')->first();
        if (!$polarNightEvent) {
            return $duration; // Событие "Полярная ночь" не найдено
        }

        $effectValue = (int)$polarNightEvent['effect_value']; // Процент увеличения времени
        $adjustedDuration = $duration + ($duration * $effectValue / 100);

        return $adjustedDuration;
    }

    /**
     * Списывает усталость в зависимости от уровня персонажа и проверяет достаточность для начала задания.
     *
     * @param array|\App\Entities\CharacterEntity $character Данные персонажа
     * @return bool Возвращает true, если усталость успешно списана, иначе false.
     */
    protected function deductTiredness($character)
    {
        $level = $character['level'];
        $currentTiredness = $character['tired'];
        $tirednessLoss = max(0.01, (1000 - $level) / 200);

        if ($currentTiredness < $tirednessLoss) {
            // Если текущая усталость меньше необходимой для списания, возвращаем false
            return false;
        }

        $newTiredness = max(0.01, $currentTiredness - $tirednessLoss);
        $newTiredness = round($newTiredness, 2);  // Округляем новое значение усталости до двух десятичных знаков
        $this->characterModel->update($character['id'], ['tired' => $newTiredness]);
        return true;
    }

}

