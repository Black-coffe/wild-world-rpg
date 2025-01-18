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
 * Класс для запуска крафта «Грунт» (soil).
 * Переписан с учётом новых методов CharacterResourceModel (getResourceForCraft / deductResourceForCraft),
 * чтобы избежать проблем с alias'ами id.
 */
class SoilCraftActionStart extends BaseAction
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
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1) Ищем задачу "craftSoil"
        $craftTask = $this->taskModel->where('name', 'craftSoil')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт грунта" (craftSoil) не найдена в БД.');
        }

        // 2) Проверяем, нет ли уже активного крафта с task_id = $craftTask['id']
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id',      $craftTask['id'])
            ->where('status',       'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже выполняется крафт \"Грунт\". " .
                "Дождись завершения или прерви задачу!"
            );
        }

        // 3) Проверяем ресурсы: хватает ли?
        if (!$this->hasEnoughResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: списание не выполнено.');
        }

        // 4) Списываем ресурсы и запускаем крафт
        if (!$this->deductResources($character['id'])) {
            // Теоретически, если вдруг не смогли списать
            return $this->sendError('Возникла ошибка при списании ресурсов.');
        }

        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем, хватает ли ресурсов (через alias-метод getResourceForCraft).
     */
    private function hasEnoughResources(int $charId): bool
    {
        // Что нужно для крафта «Грунт»
        $requiredResources = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Берём ресурс через getResourceForCraft, смотрим на charResQty
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $charId);
            if (!$row || (int)$row['charResQty'] < $neededAmount) {
                return false; // Не хватает
            }
        }
        return true; // Всего достаточно
    }

    /**
     * Списываем ресурсы (через alias-метод deductResourceForCraft),
     * возвращаем true, если всё ок.
     */
    private function deductResources(int $charId): bool
    {
        $requiredResources = [
            'Глина'     => 10,
            'Водоросли' => 5,
            'Песок'     => 26,
            'Ил'        => 15,
        ];

        foreach ($requiredResources as $resourceName => $neededAmount) {
            // Если вдруг не получилось списать — прерываем
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $charId, $neededAmount)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Создаём запись в character_tasks (статус in_work) и уведомляем.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        $duration  = $this->calculateCraftingDuration($character, $craftTask);
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
     * Пример расчёта длительности крафта, исходя из статов персонажа.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $experience = (float)($character['experience'] ?? 0);
        $agility    = (float)($character['agility']    ?? 0);
        $intellect  = (float)($character['intellect']  ?? 0);

        $expFactor  = 0.3;
        $agiFactor  = 0.3;
        $intFactor  = 0.4;

        $attrScore  = $experience * $expFactor
            + $agility    * $agiFactor
            + $intellect  * $intFactor;
        $maxAttr    = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm       = min(1.0, $attrScore / $maxAttr);

        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $adjusted    = $minDuration + ($maxDuration - $minDuration) * (1 - $norm);
        $final       = (int)round($adjusted);

        return max($minDuration, min($final, $maxDuration));
    }

    /**
     * Уведомляем об успешном запуске крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🌱 Грунт*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $imagePath = base_url('uploads/telegram/craft/components/craftSoil.jpg');

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
        ]);
    }

    /**
     * Универсальный метод для отправки ошибки и ответа на callback.
     */
    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
