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
 * Класс для запуска крафта «Каменные блоки (Stone Blocks)»
 * с поддержкой количественного крафта.
 */
class StoneBlocksCraftActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;

    /**
     * Количество для крафта, получаем из callback_data (например "craftStoneBlocks_10").
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel             = new TaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->characterResourceModel= new CharacterResourceModel();

        // Парсим callback_data, чтобы понять, сколько шт. хотим крафтить
        $data  = $callbackQuery->getData();              // например "craftStoneBlocks_25"
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int)$parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        // 1) Получаем пользователя / персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден.");
        }

        // 2) Ищем задачу "craftStoneBlocks" в таблице tasks
        $craftTask = $this->taskModel->where('name', 'craftStoneBlocks')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт каменных блоков" не найдена в базе данных.');
        }

        // 3) Проверяем, нет ли уже активной задачи
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id',      $craftTask['id'])
            ->where('status',       'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идет крафт каменных блоков! Дождись завершения или прерви задачу."
            );
        }

        // 4) Проверяем и списываем ресурсы
        //    (учитывая, что на 1 шт. нужно [Камни=36, Вода=10], а всего $this->quantity шт.)
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError('Недостаточно ресурсов для крафта. Ничего не списано!');
        }

        // 5) Запускаем процесс крафта (указываем quantity в task_settings)
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Проверяем и списываем ресурсы, умножая "на 1 шт." × $qty
     */
    private function checkAndDeductResources(int $charId, int $qty): bool
    {
        // Потребление на 1 шт.
        $requirements = [
            'Камни' => 36,
            'Вода'  => 10,
        ];

        // 1) Сначала убеждаемся, что всего хватает
        foreach ($requirements as $resourceName => $needPerOne) {
            $needTotal = $needPerOne * $qty;
            $row = $this->characterResourceModel->getResourceForCraft($resourceName, $charId);
            if (!$row || (int)$row['charResQty'] < $needTotal) {
                return false;
            }
        }

        // 2) Если хватает, списываем
        foreach ($requirements as $resourceName => $needPerOne) {
            $needTotal = $needPerOne * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($resourceName, $charId, $needTotal)) {
                return false; // вдруг ошибка при списании
            }
        }
        return true;
    }

    /**
     * Создаём запись в character_tasks, включая task_settings={"quantity": $qty}
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        $duration  = $this->calculateCraftingDuration($character, $craftTask, $qty);
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Запишем количество в поле task_settings (JSON)
        $taskSettings = json_encode(['quantity' => $qty], JSON_UNESCAPED_UNICODE);

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $userId,
            'task_id'          => $craftTask['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => $taskSettings,
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime, $qty);
    }

    /**
     * Примерная логика времени крафта (на 1 шт. × $qty).
     */
    private function calculateCraftingDuration(array $character, array $craftTask, int $qty): int
    {
        // Допустим, время на 1 шт. (используем min_duration..max_duration)
        $experience = (float)($character['experience'] ?? 0);
        $agility    = (float)($character['agility']    ?? 0);
        $intellect  = (float)($character['intellect']  ?? 0);

        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $attrScore = $experience * $expFactor
            + $agility   * $agiFactor
            + $intellect * $intFactor;
        $maxAttr   = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm      = min(1.0, $attrScore / $maxAttr);

        $minDur    = (int)($craftTask['min_duration'] ?? 4);  // 4 мин
        $maxDur    = (int)($craftTask['max_duration'] ?? 16); // 16 мин

        $oneItemDur = $minDur + ($maxDur - $minDur) * (1 - $norm);
        $oneItemDur = max($minDur, min($oneItemDur, $maxDur));

        // Умножим на кол-во шт.
        $totalDuration = (int)round($oneItemDur * $qty);

        return max($qty, $totalDuration); // не меньше, чем qty (пример)
    }

    /**
     * Уведомляем игрока о запуске крафта (учитывая $qty).
     */
    private function notifyCraftStarted(
        array $character,
        \DateTime $startTime,
        \DateTime $endTime,
        int $qty
    ): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: *🧱 Каменные блоки x{$qty} шт.*\n\n"
            . "Время крафта: *{$minutes} мин.* ⏱️\n\n"
            . "❗ Если прервать задачу — ресурсы теряются без возврата.\n\n"
            . "_О готовности узнаешь в сообщении!_";

        $imagePath = base_url('uploads/telegram/craft/components/craftStoneBlocks.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Унифицированный метод отправки ошибок
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
