<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

class StartBuildTeleportationCenterConstruction extends BaseAction
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $taskModel;
    protected $characterTaskModel;
    protected $eventModel;
    protected $activeEventModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->taskModel             = new TaskModel();
        $this->characterTaskModel    = new CharacterTaskModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
    }

    public function handle(): ServerResponse
    {
        // Закрываем "часики" на inline-кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Ошибка: пользователь или персонаж не найдены.");
        }

        // 1. Проверка переезда
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // 2. Проверка базы
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            return $this->sendError("У вас нет лагеря. Сначала разбейте лагерь.");
        }
        $campCellId = $claimedCells[0]['map_cell_id'];

        // 3. Убедимся, что игрок на базе
        if ($character['cell_number'] != $campCellId) {
            return $this->sendError("Вы не на своей базе! Переместитесь в лагерь, чтобы строить здание.");
        }

        // 4. Находим здание "TeleportationCenter"
        $building = $this->buildingModel->where('name_en', 'TeleportationCenter')->first();
        if (!$building) {
            return $this->sendError("Не найдено здание 'TeleportationCenter' в базе Buildings.");
        }

        // 5. Проверка уровня
        if ($character['level'] < $building['min_character_level']) {
            return $this->sendError("Недостаточный уровень для строительства 'Центра телепортации'.");
        }

        // 6. Проверка ресурсов
        $requiredRes = json_decode($building['required_resources'], true) ?? [];
        // + Если нужно, проверяем особые предметы (раскомментируйте при необходимости)
        // $requiredCraftedItems = [
        //     'TeleporterModule' => 1,
        //     'EnergyCrystal' => 5,
        // ];
        $requiredCraftedItems = []; // Пусто, если не нужен дополнительный крафт

        $missingResources    = $this->checkResources($character['id'], $requiredRes);
        $missingCraftedItems = $this->checkCraftedItems($character['id'], $requiredCraftedItems);

        if (!empty($missingResources) || !empty($missingCraftedItems)) {
            return $this->sendError("Недостаточно ресурсов/предметов для постройки 'Центра телепортации'.");
        }

        // 7. Проверяем, нет ли уже запущенной задачи на постройку этого здания
        //    Для этого нужно иметь в таблице 'tasks' запись, напр. {name='startBuildTeleportationCenter'} или что-то подобное
        $taskRow = $this->taskModel->where('name', 'startBuildTeleportationCenter')->first();
        if (!$taskRow) {
            return $this->sendError("Не найдена задача 'startBuildTeleportationCenter' в таблице tasks.");
        }

        // Проверяем, нет ли уже активной задачи на постройку этого здания
        $activeTask = $this->characterTaskModel->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError("Похоже, строительство 'Центра телепортации' уже запущено. Дождитесь окончания.");
        }

        // 8. Списываем ресурсы
        $this->subtractResources($character['id'], $requiredRes);
        $this->subtractCraftedItems($character['id'], $requiredCraftedItems);

        // 9. Создаём запись в character_tasks
        $startTime = new \DateTime();
        // Время строительства — в минутах, берём из $building['construction_time'] или можно варьировать
        $durationMinutes = $building['construction_time'] ?? 180;
        $endTime = (clone $startTime)->modify("+{$durationMinutes} minutes");

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'map_cell_id' => $campCellId,
                'building_id' => $building['id']
            ])
        ]);

        // Уведомим игрока об успешном запуске стройки
        $minutes = $durationMinutes;
        $text = "*Процесс строительства 'Центра телепортации' запущен!*\n\n"
            . "⏳ Время строительства: ~{$minutes} минут.\n"
            . "О готовности тебе придёт уведомление.\n";

        $imagePath = base_url('uploads/telegram/camp/teleport_center_construction.jpg'); // Подставьте путь к вашему изображению
        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Проверяем ресурсы
     */
    private function checkResources(int $characterId, array $requiredRes): array
    {
        $missing = [];
        foreach ($requiredRes as $resName => $resNeeded) {
            $resRow = $this->resourceModel->getResourceByNameEn($resName);
            if ($resRow) {
                $charRes = $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resRow['id'])
                    ->first();
                $haveAmount = $charRes ? $charRes['quantity'] : 0;
                if ($haveAmount < $resNeeded) {
                    $missing[$resName] = [
                        'have' => $haveAmount,
                        'need' => $resNeeded
                    ];
                }
            } else {
                $missing[$resName] = [
                    'have' => 0,
                    'need' => $resNeeded
                ];
            }
        }
        return $missing;
    }

    /**
     * Проверяем крафтовые предметы
     */
    private function checkCraftedItems(int $characterId, array $requiredItems): array
    {
        $missing = [];
        foreach ($requiredItems as $itemCode => $itemNeed) {
            $itemRow = $this->craftedItemsModel->getRowByName($itemCode);
            if ($itemRow) {
                $logRow = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($itemRow['id'], $characterId);
                $have = $logRow ? $logRow['quantity'] : 0;
                if ($have < $itemNeed) {
                    $missing[$itemCode] = ['have' => $have, 'need' => $itemNeed];
                }
            } else {
                $missing[$itemCode] = ['have' => 0, 'need' => $itemNeed];
            }
        }
        return $missing;
    }

    /**
     * Списываем ресурсы
     */
    private function subtractResources(int $characterId, array $requiredRes): void
    {
        foreach ($requiredRes as $resName => $resNeed) {
            $resRow = $this->resourceModel->getResourceByNameEn($resName);
            if ($resRow) {
                $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources', $resRow['id'])
                    ->set('quantity', 'quantity - '.$resNeed, false)
                    ->update();
            }
        }
    }

    /**
     * Списываем крафтовые предметы
     */
    private function subtractCraftedItems(int $characterId, array $requiredItems): void
    {
        if (empty($requiredItems)) {
            return;
        }
        $db = \Config\Database::connect();
        $db->transStart();
        foreach ($requiredItems as $itemCode => $itemNeed) {
            $itemRow = $this->craftedItemsModel->getRowByName($itemCode);
            if ($itemRow) {
                $logRow = $this->craftedItemsLogModel
                    ->where('character_id', $characterId)
                    ->where('crafted_item_id', $itemRow['id'])
                    ->first();
                if ($logRow) {
                    $newQuantity = $logRow['quantity'] - $itemNeed;
                    if ($newQuantity > 0) {
                        $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQuantity]);
                    } else {
                        // Если уходит в ноль или меньше — удаляем запись/обнуляем
                        $this->craftedItemsLogModel->delete($logRow['id']);
                    }
                }
            }
        }
        $db->transComplete();
    }

    private function sendError(string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
            'parse_mode' => 'Markdown'
        ]);
    }
}
