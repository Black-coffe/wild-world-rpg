<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\BuildingModel;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\ActiveEventModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Services\Tasks\ActiveTasksService;
use DateTime;
use DateInterval;

class StartBuildArsenalConstruction extends BaseAction
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
        // 1) Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 2) Получаем user / character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError("Ошибка: персонаж или пользователь не найден.");
        }

        // 3) Проверяем, нет ли активного переезда
        $activeTasksService = new ActiveTasksService();
        $blocked = $activeTasksService->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        );
        if ($blocked) {
            return Request::emptyResponse(); // Переезд активен, уже выслали сообщение
        }

        // 4) Проверяем, что у игрока есть лагерь и он находится в лагере
        $claimedCells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($claimedCells)) {
            return $this->sendError("У вас нет лагеря. Сначала разверните лагерь, чтобы строить здание!");
        }
        $campCell = $claimedCells[0]['map_cell_id'];
        if ($character['cell_number'] != $campCell) {
            return $this->sendError("Нужно находиться в собственном лагере, чтобы начать строительство!");
        }

        // 5) Убеждаемся, что в tasks есть запись "startBuildArsenal". Если нет — создаём.
        $taskRow = $this->taskModel->where('name', 'startBuildArsenal')->first();
        if (!$taskRow) {
            // Создаём
            $this->taskModel->insert([
                'name'                      => 'startBuildArsenal',
                'name_rus'                  => 'Строительство Арсенала',
                'description'               => 'Процесс постройки Арсенала для хранения/создания оружия и брони.',
                'min_duration'              => 60,   // Пример: 1 час
                'max_duration'              => 260,  // Пример: 3 часа
                'type'                      => 'building',
                'difficulty_level'          => 5,
                'execution_limit'           => 0,
                'parallel_execution_allowed'=> 1,
                'interruptible'             => 1,
            ]);
            // Читаем снова
            $taskRow = $this->taskModel->where('name', 'startBuildArsenal')->first();
            if (!$taskRow) {
                return $this->sendError("Не удалось автоматически создать задачу для строительства Арсенала!");
            }
        }

        // 6) Проверяем, нет ли уже АКТИВНОГО процесса "startBuildArsenal"
        $already = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work',
        ])->first();
        if ($already) {
            return $this->sendError("У тебя уже идёт процесс строительства Арсенала! Потерпи, пока он не завершится.");
        }

        // 7) Повторяем проверку ресурсов, чтобы избежать читерства.
        //    (Примерный список, который был в BuildArsenalConstruction)
        $requiredResources = [
            'Ironstone'   => 200,
            'RareMetals'  => 60,
            'Oil'         => 70,
            'Sulfur'      => 50,
        ];
        $requiredItems = [
            'metalFragments'       => 120,
            'wiring'               => 15,
            'electronicComponents' => 8,
        ];
        // Смотрим, чего не хватает
        $missRes = $this->checkResources($character['id'], $requiredResources);
        $missItems = $this->checkCraftedItems($character['id'], $requiredItems);

        if (!empty($missRes) || !empty($missItems)) {
            $text  = "Нельзя начать строительство: не хватает материалов!\n\n";
            $text .= $this->formatMissing($missRes, $missItems);
            return $this->sendError($text);
        }

        // 8) Списываем
        $this->subtractResources($character['id'], $requiredResources);
        $ok = $this->subtractCraftedItems($character['id'], $requiredItems);
        if (!$ok) {
            return $this->sendError("Ошибка при списании крафтовых предметов. Попробуй ещё раз!");
        }

        // 9) Считаем время
        $duration = $this->calculateCraftingDuration($character, $taskRow);

        // 10) Создаём запись в character_tasks
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval("PT{$duration}M"));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode(['building' => 'Arsenal']),
        ]);

        // 11) Уведомляем пользователя
        $dif    = $startTime->diff($endTime);
        $minutes = $dif->days * 1440 + $dif->h * 60 + $dif->i;

        $text = "*Строительство Арсенала начато!*\n\n"
            . "Продолжительность (ориентировочно): *{$minutes}* мин.\n"
            . "По завершении здание будет добавлено на вашу базу. \n"
            . "_Успехов в строительстве!_";

        $imgPath = base_url('uploads/telegram/camp/arsenal_in_progress.jpg');
        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imgPath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Метод для расчёта длительности в минутах (пример).
     */
    private function calculateCraftingDuration(array $character, array $taskRow): int
    {
        $minD = $taskRow['min_duration'] ?? 60;
        $maxD = $taskRow['max_duration'] ?? 180;

        // Пример: учитываем experience, agility, intellect
        $exp       = $character['experience'];
        $agility   = $character['agility'];
        $intellect = $character['intellect'];

        // Произвольные факторы
        $factorExp = 0.3;
        $factorAgi = 0.3;
        $factorInt = 0.4;

        $score = $exp * $factorExp + $agility * $factorAgi + $intellect * $factorInt;
        $maxScore = 2000.0; // гипотетический максимум

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

    /**
     * Проверяем ресурсы
     */
    private function checkResources(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if (!$row) {
                $missing[$resEn] = [
                    'need' => $need,
                    'have' => 0,
                    'name'=> $resEn."(нет в DB)"
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

    /**
     * Проверка крафтовых предметов
     */
    private function checkCraftedItems(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $need) {
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = [
                    'need' => $need,
                    'have' => 0,
                    'name' => $itemEn."(нет в DB)",
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

    /**
     * Списываем ресурсы
     */
    private function subtractResources(int $charId, array $reqs)
    {
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if ($row) {
                $this->characterResourceModel
                    ->where('id_characters', $charId)
                    ->where('id_resources', $row['id'])
                    ->set('quantity', 'quantity - ' . $need, false)
                    ->update();
            }
        }
    }

    /**
     * Списываем крафтовые предметы в транзакции
     */
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

    /**
     * Формирование списка того, чего не хватает
     */
    private function formatMissing(array $missRes, array $missItems): string
    {
        $text = "";
        if (!empty($missRes)) {
            $text .= "*Ресурсы:* \n";
            foreach ($missRes as $k => $r) {
                $text .= "- {$r['name']}: нужно {$r['need']}, у вас {$r['have']}\n";
            }
        }
        if (!empty($missItems)) {
            $text .= "\n*Предметы:* \n";
            foreach ($missItems as $k => $r) {
                $text .= "- {$r['name']}: нужно {$r['need']}, у вас {$r['have']}\n";
            }
        }
        return $text;
    }

    private function sendError(string $txt): ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $txt,
            'parse_mode' => 'Markdown',
        ]);
    }
}
