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
 * Класс для запуска крафта: «Древесные материалы».
 * Использует новые методы модели (getResourceForCraft / deductResourceForCraft),
 * чтобы избежать конфликтов с полем 'id' и alias-полями.
 */
class WoodMaterialsCraftActionStart extends BaseAction
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
        // 1) Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь не найден в базе данных или персонаж не определён.");
        }

        // 2) Ищем задачу "craftWoodMaterials"
        $craftTask = $this->taskModel->where('name', 'craftWoodMaterials')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт деревяных брусов" не найдена в базе данных.');
        }

        // 3) Проверяем, нет ли уже активной задачи данного типа (если есть - выходим)
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id',      $craftTask['id'])
            ->where('status',       'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "Извини, но у тебя уже выполняется крафт «Древесные материалы». " .
                "Дождись завершения или прерви текущий!"
            );
        }

        // 4) Проверяем, хватает ли ресурсов (через новые alias-методы)
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано!');
        }

        // 5) Списываем ресурсы
        if (!$this->deductResources($character['id'])) {
            return $this->sendError('Произошла ошибка при списании ресурсов.');
        }

        // 6) Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, достаточно ли ресурсов для крафта, не списывая.
     * Используем getResourceForCraft() с алиасами, чтобы избежать конфликтов id.
     */
    private function hasEnoughResources(int $characterId): bool
    {
        $requiredResources = [
            'Древесина' => 50,
            'Вода'      => 5,
        ];

        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Получаем алиас-данные: charResQty и т.д.
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $characterId);
            if (!$row || (int)$row['charResQty'] < $neededAmount) {
                return false; // Не хватает
            }
        }
        return true;
    }

    /**
     * Списываем ресурсы, когда точно знаем, что всего хватает.
     * Используем deductResourceForCraft() для корректного обновления.
     */
    private function deductResources(int $characterId): bool
    {
        $requiredResources = [
            'Древесина' => 50,
            'Вода'      => 5,
        ];

        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Если хоть раз не получилось списать - возвращаем false
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $characterId, $neededAmount)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Собственно запуск крафта: создаём запись в character_tasks со статусом in_work.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        // Рассчитываем длительность
        $duration  = $this->calculateCraftingDuration($character, $craftTask);
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Вставляем задачу в таблицу
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
     * Пример логики расчёта времени крафта на основе характеристик персонажа.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = (float)($character['experience'] ?? 0);
        $agility    = (float)($character['agility']    ?? 0);
        $intellect  = (float)($character['intellect']  ?? 0);

        // Весовые коэффициенты
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore   = ($experience * $expFactor)
            + ($agility    * $agiFactor)
            + ($intellect  * $intFactor);
        $maxAttr     = 1000 * ($expFactor + $agiFactor + $intFactor);
        $normalized  = min(1.0, $attrScore / $maxAttr);

        $minDuration = (int)($craftTask['min_duration'] ?? 5);
        $maxDuration = (int)($craftTask['max_duration'] ?? 15);

        $adjusted    = $minDuration + ($maxDuration - $minDuration) * (1 - $normalized);
        $final       = (int)round($adjusted);

        return max($minDuration, min($final, $maxDuration));
    }

    /**
     * Отправка сообщения об ошибке.
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }

    /**
     * Уведомление о начале крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаёшь: 🪵 Древесные материалы*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафтинг', 'callback_data' => 'crafting']
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/huge_mechanical_workbench.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
