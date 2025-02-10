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
use App\Models\EventModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\ActiveEventModel;
use DateTime;
use DateInterval;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;

/**
 * Класс для обработки нажатия "🛠️ Построить Вышку связи"
 * (callback_data = 'startBuildCommunicationTower').
 */
class StartBuildCommunicationTower extends BaseAction
{
    protected $claimedCellModel;
    protected $characterModel;
    protected $buildingModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $taskModel;
    protected $eventModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $activeEventModel;
    protected $characterTaskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->claimedCellModel       = new ClaimedCellModel();
        $this->characterModel         = new CharacterModel();
        $this->buildingModel          = new BuildingModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->taskModel              = new TaskModel();
        $this->eventModel             = new EventModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->characterTaskModel     = new CharacterTaskModel();
    }

    public function handle(): ServerResponse
    {
        // Закрываем часики
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Получаем user / character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Персонаж или пользователь не найден.");
        }

        // Проверяем переезд
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            return Request::emptyResponse();
        }

        // Проверяем, есть ли лагерь
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            return $this->sendError("У вас нет лагеря! Сперва разверните лагерь.");
        }

        // Проверяем, что мы в лагере
        $campCell = $claimedCells[0]['map_cell_id'];
        if ($character['cell_number'] != $campCell) {
            return $this->sendError("Вы не в лагере, переместитесь туда, чтобы построить здание!");
        }

        // Ищем/создаём задачу "startBuildCommunicationTower"
        $taskRow = $this->taskModel->where('name', 'startBuildCommunicationTower')->first();
        if (!$taskRow) {
            $this->taskModel->insert([
                'name'                      => 'startBuildCommunicationTower',
                'name_rus'                  => 'Строительство Вышки связи',
                'description'               => 'Начать постройку CommunicationTower',
                'min_duration'              => 60,  // пример: час
                'max_duration'              => 180, // максимум 3 часа
                'type'                      => 'building',
                'difficulty_level'          => 5,
                'execution_limit'           => 0,
                'parallel_execution_allowed'=> 1,
                'interruptible'             => 1,
            ]);
            $taskRow = $this->taskModel->where('name', 'startBuildCommunicationTower')->first();
            if (!$taskRow) {
                return $this->sendError("Не удалось создать задачу строительства Вышки связи!");
            }
        }

        // Проверяем нет ли уже АКТИВНОГО такого процесса
        $already = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work'
        ])->first();
        if ($already) {
            return $this->sendError("У вас уже идёт строительство Вышки связи!");
        }

        // Повторяем проверку ресурсов
        $requiredResources = [
            'Ironstone'  => 100,
            'RareMetals' => 20,
            'Oil'        => 30,
            'Sulfur'     => 15,
        ];
        $requiredItems = [
            'metalFragments'       => 100,
            'electronicComponents' => 12,
            'wiring'               => 12,
        ];

        $missRes   = $this->checkResources($character['id'], $requiredResources);
        $missItems = $this->checkCraftedItems($character['id'], $requiredItems);

        if (!empty($missRes) || !empty($missItems)) {
            $text  = "Нельзя начать строительство: не хватает материалов!\n\n";
            $text .= $this->formatMissing($missRes, $missItems);
            return $this->sendError($text);
        }

        // Списываем ресурсы
        $this->subtractResources($character['id'], $requiredResources);
        if (!$this->subtractCraftedItems($character['id'], $requiredItems)) {
            return $this->sendError("Ошибка списания крафтовых предметов. Попробуйте ещё раз.");
        }

        // Считаем время
        $duration = $this->calculateCraftingDuration($character, $taskRow);

        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval("PT{$duration}M"));

        // Создаём запись в character_tasks
        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode(['building' => 'CommunicationTower']),
        ]);

        $dif    = $startTime->diff($endTime);
        $minutes = $dif->days * 1440 + $dif->h * 60 + $dif->i;

        $text = "*Строительство Вышки связи начато!*\n\n"
            . "Продолжительность (примерно): *{$minutes}* мин.\n"
            . "_По завершении здание появится у вас на базе!_";

        $imgPath = base_url('uploads/telegram/camp/communication_tower_in_progress.jpg');

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imgPath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    // --- Методы вспомогательные ---
    private function calculateCraftingDuration(array $character, array $taskRow): int
    {
        $minD = $taskRow['min_duration'] ?? 60;
        $maxD = $taskRow['max_duration'] ?? 180;

        // Примерная формула
        $exp       = $character['experience'];
        $agility   = $character['agility'];
        $intellect = $character['intellect'];

        $factorExp = 0.3;
        $factorAgi = 0.3;
        $factorInt = 0.4;

        $score = $exp * $factorExp + $agility * $factorAgi + $intellect * $factorInt;
        $maxScore = 2000.0;

        $ratio = min(1.0, $score / $maxScore);
        $duration = $maxD - ($maxD - $minD) * $ratio;
        $final = round($duration);
        if ($final < $minD) {
            $final = $minD;
        }
        if ($final > $maxD) {
            $final = $maxD;
        }
        return $final;
    }

    private function checkResources(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if (!$row) {
                $missing[$resEn] = [
                    'need' => $need,
                    'have' => 0,
                    'name' => $resEn." (нет в DB)"
                ];
                continue;
            }
            $charRes = $this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', $row['id'])
                ->first();
            $have = $charRes ? $charRes['quantity'] : 0;
            if ($have < $need) {
                $missing[$resEn] = [
                    'need' => $need,
                    'have' => $have,
                    'name' => $row['name'] ?? $resEn
                ];
            }
        }
        return $missing;
    }

    private function checkCraftedItems(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $need) {
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = [
                    'need' => $need,
                    'have' => 0,
                    'name' => $itemEn." (нет в DB)",
                ];
                continue;
            }
            $log = $this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            $have = $log ? $log['quantity'] : 0;
            if ($have < $need) {
                $missing[$itemEn] = [
                    'need' => $need,
                    'have' => $have,
                    'name' => $item['name_rus'] ?? $itemEn
                ];
            }
        }
        return $missing;
    }

    private function subtractResources(int $charId, array $reqs)
    {
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if ($row) {
                $this->characterResourceModel
                    ->where('id_characters', $charId)
                    ->where('id_resources', $row['id'])
                    ->set('quantity', 'quantity - '.$need, false)
                    ->update();
            }
        }
    }

    private function subtractCraftedItems(int $charId, array $reqs): bool
    {
        $db = \Config\Database::connect();
        $db->transStart();

        foreach ($reqs as $itemEn => $need) {
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $db->transRollback();
                return false;
            }
            $log = $this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            if (!$log || $log['quantity'] < $need) {
                $db->transRollback();
                return false;
            }
            $newQty = $log['quantity'] - $need;
            if ($newQty <= 0) {
                $this->craftedItemsLogModel->delete($log['id']);
            } else {
                $this->craftedItemsLogModel->update($log['id'], ['quantity' => $newQty]);
            }
        }

        $db->transComplete();
        return $db->transStatus();
    }

    private function formatMissing(array $missRes, array $missItems): string
    {
        $text = "";
        if (!empty($missRes)) {
            $text .= "*Ресурсы:* \n";
            foreach ($missRes as $rName => $r) {
                $text .= "- {$r['name']}: нужно {$r['need']}, у вас {$r['have']}\n";
            }
        }
        if (!empty($missItems)) {
            $text .= "\n*Предметы:* \n";
            foreach ($missItems as $iName => $i) {
                $text .= "- {$i['name']}: нужно {$i['need']}, у вас {$i['have']}\n";
            }
        }
        return $text;
    }

    private function sendError(string $txt): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $txt,
            'parse_mode' => 'Markdown',
        ]);
    }
}
