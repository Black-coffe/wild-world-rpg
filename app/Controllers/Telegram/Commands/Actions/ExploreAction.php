<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\MapModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Services\PlayerDetectionService; // Подключаем PlayerDetectionService

class ExploreAction extends BaseAction
{
    protected $mapModel;
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $playerDetectionService; // Добавляем свойство для PlayerDetectionService

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->mapModel = new MapModel();
        $this->taskModel = new TaskModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->playerDetectionService = new PlayerDetectionService(); // Инициализация PlayerDetectionService
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
            $response = $this->prepareBlockedTaskResponse('cancelExploration');
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

        // Получаем локацию персонажа
        $cell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$cell) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Локация персонажа не найдена.',
            ]);
        }

        // Начало новой задачи
        $explorationTask = $this->taskModel->where('name', 'ExploreTheArea')->first();
        if (!$explorationTask) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Задача "Изучить местность" не найдена в базе данных.',
            ]);
        }

        $startTime = new \DateTime();
        $durationReduction = $this->calculateDurationReduction($character);

        // Загрузка настроек задачи из базы данных
        $explorationTask = $this->taskModel->where('name', 'ExploreTheArea')->first();
        if (!$explorationTask) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Задача "Изучить местность" не найдена в базе данных.',
            ]);
        }

        // Расчет максимального и минимального времени выполнения задачи с учетом снижения продолжительности
        $maxDuration = max(0, $explorationTask['max_duration'] - $durationReduction);
        $minDuration = $explorationTask['min_duration'];
        if ($maxDuration < $minDuration) {
            $maxDuration = $minDuration;
        }

        // Влияние Полярной ночи
        if ($this->isPolarNightActive() && $this->isCharacterInAffectedBiome($character)) {
            $maxDuration = $this->adjustDurationForPolarNight($maxDuration);
        }

        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $maxDuration . 'M'));

        // Сохранение новой задачи в базе данных
        $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id' => $explorationTask['id'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        // Ответ пользователю о начале задачи на изучение местности с указанием времени выполнения
        $interval = $startTime->diff($endTime);
        $minutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Привет, герой! 🙋‍♂️* 👋\n\n"
            . "*Ты ступил на неизведанную землю. 👣*\n\n"
            . "*Опасности таятся за каждым поворотом, но не бойся!* 💪\n\n"
            . "**Исследуй небольшой участок территории вокруг себя, будь внимателен и осторожен. 👀**\n\n"
            . "*Не торопись, подмечай все детали.* 🔍\n\n"
            . "__*Время действия: " . $minutes . " минут.*__ ⏱️\n\n"
            . "*О своих открытиях ты узнаешь в конце.* 🎁\n"
            . "Удачи в пути! 🍀\n\n"
            . "P.S. Не забудь поделиться своими находками! 🗣️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '❌ Прервать изучение', 'callback_data' => 'cancelExploration']
                ],
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        // Отправка сообщения с результатами
        $imagePath = base_url('uploads/telegram/local_biome_research.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $response = Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);

        // Интеграция PlayerDetectionService: вызываем метод для обнаружения ближайших игроков
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return $response;
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
        $tirednessLoss = max(0.01, (1000 - $level) / 100);

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
