<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel; // <-- чтобы найти baseDurability
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\TaskModel;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

// Новая интеграция:
use App\Services\Bases\BaseCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * Класс StartRobotExplorationAction.
 * Отвечает за запуск робота-исследователя (рандомная точка).
 */
class StartRobotExplorationAction extends BaseAction
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
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // ADR-086 Фаза 1b: запуск робота требует Солнечную станцию (dormant killswitch).
        // Только НОВЫЕ запуски; идущие задачи не трогаются (grandfather).
        $solarGate = new \App\Services\Player\AutomationGateService();
        if ($solarGate->blocksLaunch((int) $character['id'])) {
            $this->logRejected((int) $character['id'], 'START_ROBOT_EXPLORER', 'no_solarstation');
            return $this->sendError($solarGate->lockMessage());
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse(); // переезд идёт, сервис уже выдал ответ
        }

        $characterId = $character['id'];

        // --- NEW BLOCK: проверяем базу и положение игрока через BaseCheckService ---
        $baseCheckService = new BaseCheckService();
        $baseStatus       = $baseCheckService->checkBaseStatus($characterId);

        if (!$baseStatus['hasBase']) {
            // Нет базы => завершаем
            return $this->sendError("У тебя нет базы! Нельзя запускать робота-исследователя без базы.");
        }

        // Если у игрока ЕСТЬ база, но он НЕ на базе:
        if (!$baseStatus['isOnBase']) {
            // Проверяем покрытие вышки связи
            $coverageService = new CommunicationTowerCoverageService();
            $coverageResult  = $coverageService->checkCoverage($characterId);

            if (!$coverageResult['isCovered']) {
                // Ни физически, ни по вышке связи
                return $this->sendError(
                    "Ты находишься не на своей базе, и вышка связи не покрывает твою позицию. "
                    . "Перейди на клетку своей базы или в зону покрытия, чтобы запустить робота!"
                );
            }
            // Если покрытие есть, пропускаем дальше (виртуально «на базе»)
        }
        // --- END of new block ---

        // 2. Из callback_data извлекаем ID робота: startRobotExplorer_81 => 81
        $robotId = str_replace('startRobotExplorer_', '', $this->callbackQuery->getData());

        // 3. Ищем в справочнике здание RoboticsWorkshop
        $roboticsWorkshopRow = $this->buildingModel
            ->where('name_en', 'RoboticsWorkshop')
            ->first();
        if (!$roboticsWorkshopRow) {
            return $this->sendError('Ошибка: не найдено здание RoboticsWorkshop в справочнике.');
        }
        $roboticsWorkshopId = $roboticsWorkshopRow['id'];

        // 4. Ищем в справочнике задачу "ExploringLocationRobot"
        $taskRow = $this->taskModel
            ->where('name', 'ExploringLocationRobot')
            ->first();
        if (!$taskRow) {
            return $this->sendError('Ошибка: не найдена задача ExploringLocationRobot.');
        }
        $taskId = $taskRow['id'];

        // 5. Проверяем, нет ли уже активной задачи "ExploringLocationRobot"
        $existingRobotTask = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();

        if ($existingRobotTask) {
            return $this->sendError(
                "У тебя уже запущен робот‐исследователь! "
                . "Дождись завершения предыдущего, прежде чем запускать нового."
            );
        }

        // 6. Узнаём уровень мастерской
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsWorkshopId)
            ->first();
        // Симметрично добытчику (v0.51.647): без мастерской — отказ ДО списания прочности.
        if (!$roboticsWorkshop) {
            return $this->sendError(StartRobotGatheringAction::noWorkshopMessage());
        }
        $workshopLevel = (int) ($roboticsWorkshop['level'] ?? 1);

        // 7. Ищем запись о роботе в crafted_items_log
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

        // 7.1 Узнаём baseDurability из таблицы crafted_items
        $robotItemModel = new CraftedItemsModel();
        $robotItem      = $robotItemModel->find($robotId);
        if (!$robotItem) {
            return $this->sendError("Ошибка: не найдено описание робота #{$robotId} в crafted_items.");
        }
        $baseDurability = (int)$robotItem['durability_count'];

        // Текущее состояние робота(ов)
        $currentDurability = (int)$robotLogEntry['durability_count'];
        $currentQuantity   = (int)$robotLogEntry['quantity'];

        if ($currentDurability <= 0) {
            return $this->sendError("Данный робот не имеет прочности (durability = 0). Нельзя запустить.");
        }

        // 8. Рассчитываем время работы: 6 ч * уровень мастерской
        $hoursUntilBreakdown = 6 * $workshopLevel;
        $requiredDurability  = $hoursUntilBreakdown;

        // --- Логика «пополнения» прочности ---
        while ($currentDurability < $requiredDurability) {
            if ($currentQuantity > 1) {
                $currentQuantity--;
                $currentDurability += $baseDurability;
            } else {
                // Остался 1 робот
                $requiredDurability = $currentDurability;
                break;
            }
        }

        // 9. Списываем requiredDurability
        $newDurability = $currentDurability - $requiredDurability;
        if ($newDurability <= 0) {
            // Робот «умер»
            $newQuantity = $currentQuantity - 1;
            if ($newQuantity <= 0) {
                $this->craftedItemsLogModel->delete($robotLogEntry['id']);
            } else {
                $this->craftedItemsLogModel->update($robotLogEntry['id'], [
                    'quantity'         => $newQuantity,
                    'durability_count' => $baseDurability,
                ]);
            }
        } else {
            // Осталось > 0, значит обновляем
            $this->craftedItemsLogModel->update($robotLogEntry['id'], [
                'quantity'         => $currentQuantity,
                'durability_count' => $newDurability,
            ]);
        }

        // 10. Создаём новую задачу "ExploringLocationRobot" (рандомная точка)
        $startTime = new \DateTime();
        $endTime   = (clone $startTime)->add(new \DateInterval('PT' . $hoursUntilBreakdown . 'H'));
        $this->characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            // V18 (ADR-049): какой именно робот запущен — для tier-aware множителя
            // в CompleteRobotExplorationHandler (T2 Scout открывает больше клеток).
            'task_settings'    => json_encode(['crafted_item_id' => (int) $robotId]),
        ]);

        // E20 (ADR-120) — инструментация адопшена (успешные запуски раньше не логировались).
        $this->logActivity((int) $characterId, 'START_ROBOT_EXPLORER', "robot={$robotId} hours={$hoursUntilBreakdown}");

        // 11. Считаем, сколько роботов и суммарной прочности осталось
        $allRobotRows = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->findAll();

        $sumQuantity   = 0;
        $sumDurability = 0;
        foreach ($allRobotRows as $row) {
            $sumQuantity   += $row['quantity'];
            $sumDurability += $row['durability_count'] * $row['quantity'];
        }

        // 12. Формируем ответ
        $text = "🚀 *Ты запустил робота-исследователя!* 🔍 (рандомная точка)\n\n"
            . "🔧 Он будет работать *{$hoursUntilBreakdown} ч.* (Уровень мастерской: {$workshopLevel}).\n\n"
            . "📉 Осталось:\n"
            . "   — Роботов: *{$sumQuantity}* шт.\n"
            . "   — Общей прочности: *{$sumDurability}*\n\n"
            . "🎉 Удачи в исследовании новых территорий! 🎉";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                    ['text' => '🤖 Роботы',     'callback_data' => 'AllRobots'],
                    ['text' => '🏠 База',       'callback_data' => 'Base'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Утилитарный метод, чтобы вернуть ошибку в Telegram (и сбросить анимацию нажатия кнопки).
     */
    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
