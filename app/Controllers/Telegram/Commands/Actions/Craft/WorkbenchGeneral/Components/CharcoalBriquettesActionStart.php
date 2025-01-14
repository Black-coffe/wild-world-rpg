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
 * Пример контроллера для крафта "Угольные брикеты".
 * Использует новые методы getResourceForCraft() и deductResourceForCraft() для точного списания.
 */
class CharcoalBriquettesActionStart extends BaseAction
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
        // 1. Получаем пользователя / персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден!');
        }

        // 2. Ищем задачу "craftCharcoalBriquettes"
        $craftTask = $this->taskModel->where('name', 'craftCharcoalBriquettes')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Угольные брикеты" не найдена.');
        }

        // 3. Проверяем активную задачу
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $craftTask['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError("Уже идёт крафт \"Угольные брикеты\". Дождись завершения!");
        }

        // 4. Проверяем и списываем ресурсы
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта.');
        }

        // 5. Создаем запись о задаче и уведомляем
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Проверяет наличие и списывает ресурсы:
     * Здесь мы используем "новые" методы getResourceForCraft и deductResourceForCraft
     * чтобы избежать конфликтов alias.
     */
    private function checkAndDeductResources(int $charId): bool
    {
        $requirements = [
            ['name' => 'Древесина',       'need' => 10],
            ['name' => 'Глина',           'need' => 2],
            ['name' => 'Вода',            'need' => 2],
            ['name' => 'Угольная порода', 'need' => 20],
        ];

        // Сначала проверяем наличие
        foreach ($requirements as $req) {
            $row = $this->characterResourceModel->getResourceForCraft($req['name'], $charId);
            if (!$row || (int)$row['charResQty'] < $req['need']) {
                return false;
            }
        }

        // Теперь списываем
        foreach ($requirements as $req) {
            if (!$this->characterResourceModel->deductResourceForCraft($req['name'], $charId, $req['need'])) {
                return false;
            }
        }

        return true;
    }

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
     * Просто пример логики вычисления длительности.
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'] ?? 5;
        $maxDuration = $craftTask['max_duration'] ?? 15;

        $intellect = (float)($character['intellect'] ?? 0);
        $factor = 1 - min(1.0, $intellect / 200.0);

        $time = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);
        return (int) max($minDuration, min($maxDuration, round($time)));
    }

    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: 🪨 *Угольные брикеты*\n\n"
            . "_Время крафта: ~{$minutes} мин._\n\n"
            . "О готовности узнаешь отдельным сообщением!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт',     'callback_data' => 'crafting'],
                ],
            ],
        ];

        // Закрываем окошко с "Loading..."
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // Отправим именно фото, как вы хотели
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
     * Унифицированный метод отправки ошибки (простой текст).
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