<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel; // <-- добавляем, чтобы узнать baseDurability робота
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

// Подключаем наши сервисы
use App\Services\Bases\BaseCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * Класс, запускающий робота-добытчика ресурсов.
 * Логика похожа на StartRobotExplorationAction, но заточена
 * на задачу "GatheringResourcesRobot" и формулу времени = 2 * уровень_мастерской.
 */
class StartRobotGatheringAction extends BaseAction
{
    protected $characterTaskModel;
    protected $craftedItemsLogModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->taskModel              = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        // 1. Получаем пользователя, персонажа, чат
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден или персонаж не определён.');
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse(); // переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        // --- NEW: проверка базы и потенциальной «дистанционной» возможности ---
        $baseCheckService = new BaseCheckService();
        $baseStatus = $baseCheckService->checkBaseStatus($characterId);

        if (!$baseStatus['hasBase']) {
            // Если базы нет
            return $this->sendError(
                "У тебя нет базы! Нельзя запустить робота-добытчика, пока не построишь базу."
            );
        }

        // Если персонаж НЕ на базе, пытаемся узнать, покрывает ли его вышка связи
        if (!$baseStatus['isOnBase']) {
            $coverageService = new CommunicationTowerCoverageService();
            $coverageResult  = $coverageService->checkCoverage($characterId);

            if (!$coverageResult['isCovered']) {
                // Нет физического нахождения на базе и нет сигнала вышки
                return $this->sendError(
                    "Ты не находишься на своей базе, а вышка связи не покрывает твою позицию. "
                    . "Вернись на базу или в зону покрытия, чтобы запустить робота!"
                );
            }
            // Если покрытие есть — продолжаем, как будто «виртуально» на базе
        }

        // Из callback_data извлекаем ID робота (например, "startRobotGatherer_82" => "82")
        $robotId = str_replace('startRobotGatherer_', '', $this->callbackQuery->getData());

        // 1) Ищем в справочнике здание RoboticsWorkshop
        $roboticsWorkshopRow = $this->buildingModel
            ->where('name_en', 'RoboticsWorkshop')
            ->first();

        if (!$roboticsWorkshopRow) {
            return $this->sendError('Ошибка: не найдено здание RoboticsWorkshop в справочнике.');
        }
        $roboticsWorkshopId = $roboticsWorkshopRow['id'];

        // 2) Ищем в справочнике задачу "GatheringResourcesRobot"
        $taskRow = $this->taskModel
            ->where('name', 'GatheringResourcesRobot')
            ->first();

        if (!$taskRow) {
            return $this->sendError('Ошибка: не найдена задача GatheringResourcesRobot.');
        }
        $taskId = $taskRow['id'];

        // 3) Проверяем, нет ли уже активной задачи "GatheringResourcesRobot"
        $existingRobotTask = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();

        if ($existingRobotTask) {
            return $this->sendError(
                "У тебя уже запущен робот‐добытчик! Дождись завершения предыдущего."
            );
        }

        // 4) Узнаём уровень мастерской
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsWorkshopId)
            ->first();

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // 5) Ищем в crafted_items_log любую запись, где есть нужный робот (crafted_item_id = $robotId) и quantity > 0
        $robotLogEntry = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();

        if (!$robotLogEntry) {
            return $this->sendError(
                "У тебя нет роботов данного типа. Возможно, они закончились или не были скрафчены."
            );
        }

        // Узнаём базовую прочность из таблицы crafted_items (поле durability_count)
        $robotItemModel = new CraftedItemsModel();
        $robotData      = $robotItemModel->find($robotId);
        if (!$robotData) {
            return $this->sendError("Ошибка: не найдено описание робота #{$robotId} в crafted_items.");
        }

        $baseDurability    = (int) $robotData['durability_count'];
        $currentDurability = (int) $robotLogEntry['durability_count'];
        $currentQuantity   = (int) $robotLogEntry['quantity'];

        if ($currentDurability <= 0) {
            return $this->sendError(
                "Данный робот не имеет прочности (durability = 0). Нельзя запустить."
            );
        }

        // 6) Рассчитываем время работы: 2 ч * уровень мастерской
        $hoursUntilBreakdown = 2 * $workshopLevel;

        // «Стоимость» в прочности = hoursUntilBreakdown
        $requiredDurability = $hoursUntilBreakdown;

        // Если не хватает прочности, берём «запасных» роботов
        while ($currentDurability < $requiredDurability) {
            if ($currentQuantity > 1) {
                $currentQuantity--;
                $currentDurability += $baseDurability;
            } else {
                // Остался один робот => частичный запуск
                $requiredDurability = $currentDurability;
                break;
            }
        }

        // Списываем нужное количество
        $newDurability = $currentDurability - $requiredDurability;
        if ($newDurability <= 0) {
            $newQuantity = $currentQuantity - 1;
            if ($newQuantity <= 0) {
                // удаляем запись
                $this->craftedItemsLogModel->delete($robotLogEntry['id']);
            } else {
                // обновляем
                $this->craftedItemsLogModel->update($robotLogEntry['id'], [
                    'quantity'         => $newQuantity,
                    'durability_count' => $baseDurability,
                ]);
            }
        } else {
            $this->craftedItemsLogModel->update($robotLogEntry['id'], [
                'quantity'         => $currentQuantity,
                'durability_count' => $newDurability,
            ]);
        }

        // 7) Создаём новую задачу (GatheringResourcesRobot) в character_tasks
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $hoursUntilBreakdown . 'H'));

        $this->characterTaskModel->save([
            'character_id'     => $characterId,
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        // 8) Считаем, сколько роботов и «общей прочности» осталось
        $allRobotRows = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->findAll();

        $sumQuantity   = 0;
        $sumDurability = 0;
        foreach ($allRobotRows as $row) {
            $sumQuantity   += $row['quantity'];
            $sumDurability += ($row['durability_count'] * $row['quantity']);
        }

        // 9) Формируем текст ответа
        $text = "🚀 *Ты запустил робота-добытчика ресурсов!* ⚙\n\n"
            . "🔧 Он будет работать *{$hoursUntilBreakdown} ч.* (Уровень мастерской: {$workshopLevel}).\n\n"
            . "📉 Остаток роботов после запуска:\n"
            . "   — Роботов: *{$sumQuantity}* шт.\n"
            . "   — Общая прочность: *{$sumDurability}*\n\n"
            . "⛏ Ожидай окончания работы, пока робот соберёт всё возможное!";

        // Кнопки для удобства
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🤖 Роботы',     'callback_data' => 'AllRobots'],
                    ['text' => '🏠 База',       'callback_data' => 'Base'],
                ],
            ]
        ];

        // Для наглядности выводим картинку
        $imagePath = base_url('uploads/telegram/craft/standard/robot_gatherer.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Утилитарный метод, чтобы вернуть ошибку в Telegram (и сбросить анимацию нажатия кнопки).
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
