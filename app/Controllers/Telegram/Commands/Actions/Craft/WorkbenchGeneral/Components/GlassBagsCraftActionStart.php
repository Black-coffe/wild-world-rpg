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
 * Этот класс отвечает за запуск крафта «Стекло пакеты».
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
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // 1) Найдём в базе данных задачу "craftGlassBags"
        $craftTask = $this->taskModel->where('name', 'craftGlassBags')->first();
        if (!$craftTask) {
            return $this->sendError('Задача "Крафт Стекло пакеты" не найдена в базе данных.');
        }

        // 2) Проверка, нет ли уже запущенной задачи крафта GlassBags
        //    Если есть — выходим без списания ресурсов!
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $craftTask['id'],
            'status'       => 'in_work'
        ])->first();

        if ($activeTask) {
            return $this->sendError(
                "Ты уже крафтишь \"Стекло пакеты\". "
                . "Дождись завершения! Или прерви текущий крафт, чтобы начать заново."
            );
        }

        // 3) Раз задачи нет — теперь можно списывать ресурсы
        if (!$this->checkAndDeductResources($character['id'])) {
            return $this->sendError('Недостаточно ресурсов для крафта: списание не выполнено.');
        }

        // 4) Стартуем процесс крафта (создаём запись в character_tasks)
        return $this->startCraftingProcess($character, $user['id'], $craftTask);
    }

    /**
     * Сначала проверяем, хватает ли ресурсов,
     * и только потом списываем.
     */
    private function checkAndDeductResources(int $characterId): bool
    {
        $requiredResources = [
            'Древесина'      => 10,
            'Песок'          => 50,
            'Базальт'        => 10,
            'Лавовый камень' => 8,
        ];

        // Сначала проверяем наличие
        foreach ($requiredResources as $resName => $neededAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
            if (!$resource || $resource['quantity'] < $neededAmount) {
                return false; // Не хватает
            }
        }

        // Если все ресурсы есть — уменьшаем количество
        foreach ($requiredResources as $resName => $neededAmount) {
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $characterId);
            // Обновляем запись
            // Важно: $resource['id'] — это ID из character_resources, а не из resources
            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $resource['quantity'] - $neededAmount
            ]);
        }

        return true;
    }

    /**
     * Создание записи задачи крафта в БД,
     * вычисляем время крафта и отправляем ответ о старте.
     */
    private function startCraftingProcess(array $character, int $userId, array $craftTask): ServerResponse
    {
        // Расчёт времени
        $duration = $this->calculateCraftingDuration($character, $craftTask);

        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $duration . 'M'));

        // Создаём запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'      => $character['id'],
            'telegram_user_id'  => $userId,
            'task_id'           => $craftTask['id'],
            'start_time'        => $startTime->format('Y-m-d H:i:s'),
            'end_time'          => $endTime->format('Y-m-d H:i:s'),
            'status'            => 'in_work',
        ]);

        return $this->notifyCraftStarted($character, $startTime, $endTime);
    }

    /**
     * Пример расчёта времени (упрощённая логика).
     */
    private function calculateCraftingDuration(array $character, array $craftTask): int
    {
        $minDuration = $craftTask['min_duration'];
        $maxDuration = $craftTask['max_duration'];

        // Пример: чем выше интеллект, тем быстрее
        $intellect = $character['intellect'];
        // Допустим, при intellect=100 задача будет на 50% короче
        // при intellect=0 — без скидки
        $factor = 1 - min(1.0, $intellect / 200.0);

        $baseTime = $maxDuration - ($maxDuration - $minDuration) * (1 - $factor);

        // Округлим
        return max($minDuration, min($maxDuration, round($baseTime)));
    }

    /**
     * Отправляем пользователю сообщение о начале крафта.
     */
    private function notifyCraftStarted(array $character, \DateTime $startTime, \DateTime $endTime): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;

        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: *Стекло пакеты* 🪟\n\n"
            . "Время крафта: *{$minutes}* минут.\n"
            . "О результате сообщим отдельно! 🍀\n\n"
            . "_Удачи!_\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                    ['text' => '🛠️ Крафт',       'callback_data' => 'crafting']
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Упрощённый метод для отправки сообщения об ошибке.
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
