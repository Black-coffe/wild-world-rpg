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
 * Класс GlassBagsCraftActionStart:
 * Запуск крафта «Стеклопакеты» с использованием новых методов модели CharacterResourceModel,
 * работающих через alias (getResourceForCraft / deductResourceForCraft).
 */
class GlassBagsCraftActionStart extends BaseAction
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

    public function handle(): ServerResponse
    {
        // 1. Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе или персонаж не определён!');
        }

        // 2. Ищем задачу "craftGlassBags" в таблице tasks
        $craftTask = $this->taskModel->where('name', 'craftGlassBags')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Стеклопакеты" не найдена в базе данных!');
        }

        // 3. Проверяем, нет ли уже активной задачи крафта "craftGlassBags"
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            // Уже крафтим этот предмет
            return $this->sendError(
                "Ты уже крафтишь \"Стеклопакеты\". " .
                "Дождись завершения или прерви текущий крафт!"
            );
        }

        // 4. Проверяем ресурсы и списываем с помощью алиасных методов
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: списание не выполнено.');
        }

        // 5. Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем ресурсы (через getResourceForCraft) и списываем (через deductResourceForCraft).
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        // Перечень ресурсов для крафта "Стеклопакеты"
        $requiredResources = [
            'Древесина'      => 10,
            'Песок'          => 50,
            'Базальт'        => 10,
            'Лавовый камень' => 8,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resName => $neededAmount) {
            $row = $this->characterResourceModel->getResourceForCraft($resName, $characterId);
            if (!$row || (int)$row['charResQty'] < $neededAmount) {
                return false; // Не хватает хотя бы одного ресурса
            }
        }

        // Затем списываем
        foreach ($requiredResources as $resName => $neededAmount) {
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $characterId, $neededAmount)) {
                // Если вдруг не получилось списать, выходим
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись задачи в character_tasks и уведомляем о начале крафта.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        // Вычисляем время крафта (упрощённый пример)
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Создаём запись в таблице character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        // Уведомляем о старте крафта
        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    /**
     * Примерный расчёт времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        // Допустим, интеллект ускоряет крафт
        $intellect = (float)($character['intellect'] ?? 0);
        $factor    = 1 - min(1.0, $intellect / 200.0);

        $baseTime = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        return (int)max($minDuration, min($maxDuration, round($baseTime)));
    }

    /**
     * Отправляем сообщение об успешном начале крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: *Стеклопакеты* 🪟\n"
            . "Примерное время крафта: *{$minutes}* мин.\n\n"
            . "_О результате сообщим дополнительно!_\n";

        // Добавляем inline-кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт',     'callback_data' => 'crafting'],
                ],
            ],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Можно отправить фото (как в других примерах)
        // Но здесь оставим текст. Или, если хотите, отправьте картинку:
        // $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');
        // return Request::sendPhoto([...]);

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Унифицированный метод для отправки ошибки.
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
