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

        if (!$this->deductTiredness($character)) {
            // Если усталость недостаточна для начала задания, сообщаем об этом пользователю
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => "Твоя выносливость слишком низкая (текущий уровень: {$character['tired']}), нужно отдохнуть перед следующим приключением!",
            ]);
        }

        if (!$this->checkParallelExecutionAllowed($character['id'])) {
            $response = $this->prepareBlockedTaskResponse('cancelGather');
            Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
            ]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => $response['text'],
                'reply_markup' => $response['reply_markup'],
                'parse_mode' => 'Markdown',
            ]);
        }

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

        $resources = $this->resourcesModel
            ->like('biome_id', (string)$biome['id'], 'both')
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

        // Логика создания задачи сбора ресурсов
        $taskID = $this->createGatherTask($character, $user, $gatherTask);
        // Формирование и отправка ответного сообщения
        return $this->sendGatherResponse($character, $biome, $gatherTask, $taskID);
    }

    protected function createGatherTask($character, $user, $gatherTask)
    {
        $startTime = new \DateTime();
        $durationReduction = $this->calculateDurationReduction($character);

        // Расчет максимального и минимального времени выполнения задачи
        $maxDuration = max(0, $gatherTask['max_duration'] - $durationReduction);
        $minDuration = $gatherTask['min_duration'];
        if ($maxDuration < $minDuration) {
            $maxDuration = $minDuration;
        }

        // Проверка активности "Полярной ночи" и корректировка продолжительности
        if ($this->isPolarNightActive() && $this->isCharacterInAffectedBiome($character)) {
            $maxDuration = $this->adjustDurationForPolarNight($maxDuration);
        }

        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $maxDuration . 'M'));

        // Сохранение новой задачи в базе данных
        $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id' => $gatherTask['id'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        // Получение ID последней вставленной строки
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

        return Request::sendPhoto([
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
     * @param array $character Данные персонажа
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

