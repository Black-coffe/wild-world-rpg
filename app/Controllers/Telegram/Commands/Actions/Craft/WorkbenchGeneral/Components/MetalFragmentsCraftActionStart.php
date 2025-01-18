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
 * Класс для запуска крафта «Металл фрагменты».
 * Используем НОВЫЕ методы модели CharacterResourceModel (getResourceForCraft / deductResourceForCraft),
 * чтобы избежать конфликтов alias'ов.
 */
class MetalFragmentsCraftActionStart extends BaseAction
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
     * Точка входа при нажатии кнопки «Крафтить металл фрагменты».
     */
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе или персонаж не определён.');
        }

        // 1) Ищем задачу "craftMetalFragments"
        $craftTask = $this->taskModel->where('name', 'craftMetalFragments')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт металлических фрагментов" не найдена в базе данных.');
        }

        // 2) Проверяем, нет ли у персонажа активного крафта этого типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();

        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт «Металл фрагменты». " .
                "Дождись завершения или прерви задачу!"
            );
        }

        // 3) Проверяем и списываем ресурсы
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: ничего не списано.');
        }

        // 4) Стартуем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяем ресурсы (через getResourceForCraft) и списываем (через deductResourceForCraft).
     */
    private function checkAndDeductResources(int $charId): bool
    {
        // Что нужно для «Металл фрагментов»
        $requiredResources = [
            'Железная руда' => 100,
            'Древесина'     => 10,
            'Песок'         => 1,
        ];

        // Сначала убеждаемся, что всего хватает
        foreach ($requiredResources as $resourceName => $amountNeeded) {
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $charId);
            if (!$row || (int)$row['charResQty'] < $amountNeeded) {
                // Хоть одного ресурса не хватает => отмена
                return false;
            }
        }

        // Если всего хватает — списываем
        foreach ($requiredResources as $resourceName => $amountNeeded) {
            // Если вдруг не получилось списать (например, конкурирующее списание в другом процессе) — отмена
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $charId, $amountNeeded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создаём запись крафта (character_tasks), указываем время,
     * и отправляем сообщение об успешном запуске.
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
     * Пример вычисления времени крафта.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $exp   = (float)($character['experience'] ?? 0);
        $agi   = (float)($character['agility']    ?? 0);
        $intel = (float)($character['intellect']  ?? 0);

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore = $exp * $expFactor + $agi * $agiFactor + $intel * $intFactor;
        $maxAttr   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm      = min(1.0, $attrScore / $maxAttr);

        $duration  = $minDuration + ($maxDuration - $minDuration) * (1 - $norm);
        return (int)max($minDuration, min($duration, $maxDuration));
    }

    /**
     * Уведомляем пользователя об успешном запуске крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "*Ты создаешь: 🔩 Металл фрагменты*\n\n"
            . "__*Время крафта: {$minutes} минут.*__ ⏱️\n\n"
            . "*О готовности ты узнаешь в сообщении.* 🎁\n\n"
            . "P.S. _Не забудь поделиться своими находками!_ 🗣️\n";

        $imagePath = base_url('uploads/telegram/craft/components/craftMetalFragments.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
        ]);
    }

    /**
     * Универсальный метод для отправки ошибок.
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
