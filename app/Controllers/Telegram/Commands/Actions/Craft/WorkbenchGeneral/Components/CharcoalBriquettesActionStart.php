<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActiveEventModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, запускающий количественный крафт "Угольных брикетов" (CharcoalBriquettes).
 */
class CharcoalBriquettesActionStart extends BaseAction
{
    protected $taskModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    /**
     * Количество брикетов, извлекаемое из callback_data. По умолчанию 1.
     */
    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();

        // Например: "craftCharcoalBriquettes_10" => ["craftCharcoalBriquettes", "10"] => quantity=10
        $data  = $callbackQuery->getData();
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
            return $this->sendError('Пользователь или персонаж не найден!');
        }

        // 2) Находим задачу "craftCharcoalBriquettes"
        $craftTask = $this->taskModel->where('name', 'craftCharcoalBriquettes')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Угольные брикеты" не найдена в базе данных.');
        }

        // 3) Проверяем, нет ли активной задачи этого типа
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError("Уже идёт крафт \"Угольные брикеты\". Дождись завершения или прерви (ресурсы пропадут)!");
        }

        // 4) Проверка / списание ресурсов (× $this->quantity)
        if (!$this->checkAndDeductResources($character['id'], $this->quantity)) {
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт. угольных брикетов.");
        }

        // 5) Создаём запись о задаче + уведомляем игрока
        return $this->startCraftingProcess($character, $user['id'], $craftTask, $this->quantity);
    }

    /**
     * Списываем ресурсы, умножая базовые нормы на $qty.
     */
    private function checkAndDeductResources(int $charId, int $qty): bool
    {
        // Нормы на 1 шт.
        $requirements = [
            ['name' => 'Древесина',       'need' => 10],
            ['name' => 'Глина',           'need' => 2],
            ['name' => 'Вода',            'need' => 2],
            ['name' => 'Угольная порода', 'need' => 20],
        ];

        // Сначала проверяем наличие
        foreach ($requirements as $req) {
            $row = $this->characterResourceModel->getResourceForCraft($req['name'], $charId);
            $have = $row ? (int)$row['charResQty'] : 0;
            if ($have < $req['need'] * $qty) {
                return false; // Не хватает
            }
        }

        // Теперь списываем
        foreach ($requirements as $req) {
            $totalNeeded = $req['need'] * $qty;
            if (!$this->characterResourceModel->deductResourceForCraft($req['name'], $charId, $totalNeeded)) {
                return false; // Списание не удалось
            }
        }

        return true;
    }

    private function startCraftingProcess(array $character, int $userId, array $craftTask, int $qty): ServerResponse
    {
        // Считаем время (на 1 шт.), умножаем на qty
        $durationForOne = $this->calculateCraftingDuration($character, $craftTask);
        $totalDuration  = $durationForOne * $qty;

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $totalDuration . 'M'));

        // Запоминаем quantity в task_settings
        $taskSettings = [
            'quantity' => $qty
        ];

        // Создаём запись в character_tasks
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
     * Примерная формула. Чем выше интеллект, тем меньше время.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 16;

        $intellect  = (float)($character['intellect'] ?? 0);
        $factor     = 1 - min(1.0, $intellect / 200.0);

        $timeRaw    = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        $time       = (int) round($timeRaw);

        return max($minDuration, min($maxDuration, $time));
    }

    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime, int $qty): ServerResponse
    {
        $interval     = $startTime->diff($endTime);
        $totalMinutes = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: 🪨 *Угольные брикеты* x{$qty} шт.\n\n"
            . "Примерное время: ~{$totalMinutes} мин.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в отдельном сообщении._";

        $imagePath = base_url('uploads/telegram/craft/components/craftCharcoalBriquettes.png');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Унифицированный вывод ошибки.
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
