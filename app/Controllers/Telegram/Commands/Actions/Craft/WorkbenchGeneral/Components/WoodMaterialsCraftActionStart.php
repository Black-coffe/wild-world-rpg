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
 * Класс для запуска крафта: «Древесные материалы» (Wood Materials).
 * Теперь обрабатывает множественный крафт по аналогии с другими компонентами (брикеты, фрагменты).
 */
class WoodMaterialsCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * По умолчанию 1 штука, но из callback_data (например, "craftWoodMaterials_10")
     * мы можем вычитать любое число.
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();

        // Парсим callback_data вида "craftWoodMaterials_5" => quantity=5
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int) $parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        // 1) Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь не найден или персонаж отсутствует.");
        }

        // 2) Ищем задачу "craftWoodMaterials" (в таблице tasks)
        $craftTask = $this->taskModel->where('name', 'craftWoodMaterials')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт деревяных брусов" не найдена в базе данных.');
        }

        // 3) Проверяем, нет ли уже активной задачи этого же типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id',      $craftTask['id'])
            ->where('status',       'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт «Древесные материалы». " .
                "Дождись завершения или прерви текущую задачу!"
            );
        }

        // 4) Проверяем / списываем ресурсы (умножая базовые требования на $this->quantity)
        if (!$this->checkResources($character['id'], $this->quantity)) {
            return $this->sendError(
                "Недостаточно ресурсов для крафта {$this->quantity} шт. древесных материалов."
            );
        }

        // 5) Запускаем процесс крафта
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Проверяем, достаточно ли ресурсов, и сразу списываем их (чтобы не пришлось два раза гонять БД).
     * Если вам удобнее сперва проверять — потом списывать, можно разбить логику на 2 метода (как в других примерах).
     */
    private function checkResources(int $characterId, int $qty): bool
    {
        // Базовые требования на 1 шт.
        $requiredResources = [
            'Древесина' => 50,
            'Вода'      => 5,
        ];

        // Сначала проверяем наличие
        foreach ($requiredResources as $resName => $baseNeed) {
            $totalNeed = $baseNeed * $qty;
            $row       = $this->characterResourceModel->getResourceForCraft($resName, $characterId);
            $have      = $row ? (int)$row['charResQty'] : 0;
            if ($have < $totalNeed) {
                return false;
            }
        }

        // Если всего хватает — списываем
        foreach ($requiredResources as $resName => $baseNeed) {
            $totalNeed = $baseNeed * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resName, $characterId, $totalNeed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Записываем новую задачу (quantity в task_settings) и уведомляем игрока о начале.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Считаем время на 1 штуку
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        // И умножаем на общее количество
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Сохраняем количество в поле task_settings (JSON)
        $taskSettings = [
            'quantity' => $qty
        ];

        // Создаём запись о задаче
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($taskSettings),
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Пример расчёта времени на 1 шт. (можно брать из tasks: min_duration, max_duration).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = (int)($craftTask['min_duration'] ?? 4);
        $maxDuration = (int)($craftTask['max_duration'] ?? 16);

        // Условная формула
        $exp  = (float)($character['experience'] ?? 0);
        $agi  = (float)($character['agility']    ?? 0);
        $int  = (float)($character['intellect']  ?? 0);

        // Можно гибко варьировать
        $expFactor = 0.2;
        $agiFactor = 0.3;
        $intFactor = 0.5;

        $score   = $exp * $expFactor + $agi * $agiFactor + $int * $intFactor;
        $maxAttr = 1000 * ($expFactor + $agiFactor + $intFactor); // 1000 - условный кап
        $ratio   = ($maxAttr > 0) ? min(1.0, $score / $maxAttr) : 0;

        $timeRaw = $minDuration + ($maxDuration - $minDuration) * (1 - $ratio);
        $time    = (int) round($timeRaw);

        return max($minDuration, min($time, $maxDuration));
    }

    /**
     * Отправляем игроку уведомление о начале крафта (на X штук).
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: 🪵 *Древесные материалы* x{$qty} шт.\n\n"
            . "**Примерное время:** ~{$minutes} мин.\n\n"
            . "❗Прерывание задачи = потеря ресурсов.\n\n"
            . "_Жди уведомления о завершении!_";

        $imagePath = base_url('uploads/telegram/craft/components/craftWoodMaterials.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Универсальный метод для отправки ошибки.
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
