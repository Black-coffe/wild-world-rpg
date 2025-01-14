<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс FertilizerCraftActionStart:
 * Запускает процесс крафта «Удобрение», списывая ресурсы через новые методы (alias) для безопасной работы.
 */
class FertilizerCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();
    }

    /**
     * Главный метод обработки нажатия «Крафт удобрений».
     */
    public function handle(): ServerResponse
    {
        // Получаем данные пользователя/персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1) Ищем задачу "craftFertilizer" в базе
        $craftTask = $this->taskModel->where('name', 'craftFertilizer')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт удобрений" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли уже активной задачи этого крафта у персонажа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Уже идёт крафт
            return $this->sendError(
                "Извини, но у тебя уже выполняется крафт удобрений. " .
                "Дождись завершения, прежде чем начинать новый!"
            );
        }

        // 3) Проверяем и списываем ресурсы
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Всё ок — запускаем крафт
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем ресурсы, используя alias-методы getResourceForCraft() и deductResourceForCraft().
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        // Необходимые ресурсы для крафта удобрений
        $requiredResources = [
            'Кости животных' => 1,
            'Вода'          => 5,
            'Водоросли'     => 20,
            'Ил'            => 10,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $characterId);
            if (!$row || (int)$row['charResQty'] < $requiredAmount) {
                return false;
            }
        }

        // Если хватает — списываем
        foreach ($requiredResources as $resourceName => $requiredAmount) {
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $characterId, $requiredAmount)) {
                // Если вдруг не получилось списать
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись в character_tasks и шлём сообщение о старте крафта.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    /**
     * Примерный расчёт времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $experience = $character['experience'] ?? 0;
        $agility    = $character['agility']    ?? 0;
        $intellect  = $character['intellect']  ?? 0;

        // Условная формула для примерного расчёта
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attributeScore = ($experience * $expFactor) + ($agility * $agiFactor) + ($intellect * $intFactor);
        $maxAttributeScore = 1000 * ($expFactor + $agiFactor + $intFactor);

        $normalized = $attributeScore / $maxAttributeScore;
        if ($normalized > 1) {
            $normalized = 1;
        }

        // Чем выше norm, тем меньше фактическое время
        $duration = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        return (int) max($minDuration, min($maxDuration, round($duration)));
    }

    /**
     * Сообщение об успехе: выдаём фото + клавиатуру.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🌿 Удобрение*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        // Формируем inline-кнопки (не удаляем).
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '🏠 База',             'callback_data' => 'Base'],
                ],
            ],
        ];

        // Закрываем "Loading..." в Telegram
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Унифицированный метод для отправки ошибки.
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
