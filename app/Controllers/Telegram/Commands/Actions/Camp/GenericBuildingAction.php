<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use Config\Buildings;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * F2.1 — generic-handler начала постройки любого здания.
 *
 * Заменяет 23 копипастных `Start*BuildConstruction.php` /
 * `Build*Construction.php` (~7980 строк дубля). Поведение определяется
 * рецептом из `app/Config/Buildings.php`, ключ строения передаётся
 * через callback_data (`genericStartBuild_Arsenal`).
 *
 * S1 (v0.51.182+) — все 12 зданий ходят через этот handler; legacy
 * `Build*Construction.php` удалены, preview-экраны → {@see GenericBuildingInfoAction}.
 *
 * Поведенческая совместимость с (удалённым) `StartBuildArsenalConstruction`:
 *   - Те же шаги: lookup лагеря → проверка уровня → проверка зависимостей
 *     → проверка ресурсов и крафта → транзакция списания + insert в
 *     character_tasks → Telegram-уведомление.
 *   - Те же сообщения, та же картинка.
 *   - Те же rejected-логи (F1.10 logRejected — пишем в action_log).
 *
 * Известные различия от старого кода (преднамеренные улучшения):
 *   - Нет 11+ моделей в конструкторе — только нужные для конкретного
 *     рецепта.
 *   - Транзакция всегда обёрнута (F0.6 подход).
 *   - REJECTED-точки логируются (F1.10 подход).
 */
class GenericBuildingAction extends BaseAction
{
    private ClaimedCellModel       $claimedCellModel;
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    // $taskModel — наследуется от BaseAction (protected). Переопределение
    // как private здесь даёт PHP 8 fatal "access level must be ... or weaker".

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        // $this->taskModel инициализируется в BaseAction::__construct()
    }

    public function handle(): ServerResponse
    {
        // 1. ack-callback (убираем «часики»)
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2. парсим building_key из callback_data — формат: genericStartBuild_Arsenal
        $callbackData = $this->callbackQuery->getData();
        $parts = explode('_', $callbackData, 2);
        $buildingKey = $parts[1] ?? '';
        if ($buildingKey === '') {
            return $this->sendError('Не указан тип здания.');
        }

        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестное здание: {$buildingKey}");
        }

        // 3. user/character (общий boilerplate F1.6)
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Персонаж не найден. Попробуйте /start.');
        }

        // 4. ADR-095 Фаза 1b: «активная база» — строить можно только стоя на своей базе
        // (на той, которую и застраиваешь). Чинит мульти-бэйс (раньше сверялись с первой
        // базой) и закрывает постройку «в чистом поле». GenericBuildingInfoAction уже
        // гейтит кнопку «Строить» по этому же признаку — здесь defense-in-depth.
        $currentCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $activeBase  = $this->claimedCellModel->findActiveCell((int) $character['id'], $currentCell);
        if ($activeBase === null) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'not_on_base');
            return $this->sendError('Ты не на своей базе. Постройки возводятся только когда стоишь на базе — телепортируйся или дойди до неё.');
        }

        // 4b. ADR-095 Фаза 1b: лимит построек на ячейку (built + in-flight build tasks).
        $limitSvc   = new \App\Services\Bases\BaseLimitService();
        $maxPerCell = $limitSvc->maxBuildingsPerCell();
        $built      = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $currentCell)
            ->countAllResults();
        $built      = is_numeric($built) ? (int) $built : 0;
        $db         = \Config\Database::connect();
        $inflightRaw = $db->table('character_tasks ct')
            ->join('tasks t', 't.id = ct.task_id')
            ->where('ct.character_id', $character['id'])
            ->whereIn('ct.status', ['in_work', 'queued'])
            ->where('t.handler_key', 'generic_building')
            ->countAllResults();
        $inflight   = is_numeric($inflightRaw) ? (int) $inflightRaw : 0;
        if ($built + $inflight >= $maxPerCell) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'cell_full', [
                'built' => $built, 'inflight' => $inflight, 'max' => $maxPerCell,
            ]);
            return $this->sendError("На этой базе уже максимум построек (*{$maxPerCell}*): возведено {$built}, в работе {$inflight}. Снеси что-нибудь или развивай другую базу.");
        }

        // 5. Уровень персонажа
        if ((int) $character['level'] < $recipe['level_required']) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'low_level', [
                'required' => $recipe['level_required'],
                'have'     => $character['level'],
            ]);
            return $this->sendError("Нужен уровень не ниже *{$recipe['level_required']}* для постройки {$recipe['name_rus']}.");
        }

        // 6. Зависимости (другие здания должны быть построены)
        $missingDeps = $this->checkDependencies($character['id'], $recipe['dependencies']);
        if (!empty($missingDeps)) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'missing_deps', ['missing' => $missingDeps]);
            $list = implode(', ', $missingDeps);
            return $this->sendError("Сначала постройте: {$list}");
        }

        // 7. tasks-row (нужен min/max duration)
        $taskRow = $this->taskModel->where('name', $recipe['task_name'])->first();
        if (!$taskRow) {
            return $this->sendError("Задача '{$recipe['task_name']}' не найдена в таблице tasks.");
        }

        // 8. Проверка ресурсов
        $missRes   = $this->checkResources($character['id'], $recipe['resources']);
        $missItems = $this->checkCraftedItems($character['id'], $recipe['crafted_items']);
        if (!empty($missRes) || !empty($missItems)) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'missing_materials', [
                'missing_resources' => $missRes,
                'missing_items'     => $missItems,
            ]);
            $text = "Не хватает материалов для {$recipe['name_rus']}:\n\n"
                . $this->formatMissing($missRes, $missItems);
            return $this->sendError($text);
        }

        // 9. Списание + создание задачи в одной транзакции (F0.6)
        $db = \Config\Database::connect();
        $db->transStart();

        $this->subtractResources($character['id'], $recipe['resources']);
        $this->subtractCraftedItems($character['id'], $recipe['crafted_items']);

        $duration  = $this->calculateDuration($character, $taskRow);
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval("PT{$duration}M"));

        // ADR-102: фиксируем базу-цель постройки (активная база, на которой стоим —
        // гейт выше это гарантировал). Completion-handler привяжет здание к ней,
        // а не к клетке, где игрок окажется на момент завершения задачи.
        $taskSettings = array_merge($recipe['task_settings'], ['base_cell' => $currentCell]);

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($taskSettings),
        ]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[GenericBuildingAction:{$buildingKey}] транзакция упала для character {$character['id']}");
            return $this->sendError('Ошибка при создании задачи строительства. Попробуйте ещё раз.');
        }

        // 10. Уведомление
        // ADR-143: легенда области действия — стройка всегда «🏠 Только на базе»
        // (гейт findActiveCell выше), занятость берём из флага задачи (у зданий =1,
        // фоновая → можно уходить, пока строится).
        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background  = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        $minutes = $duration;
        $text = "*Строительство {$recipe['name_rus']} начато!*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_BUILD, $background) . "\n\n"
            . "Длительность: ~{$minutes} мин.\n"
            . "По завершении здание будет добавлено на базу.";

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    // ------------------------------------------------------------------
    // helpers (1:1 копия из удалённого StartBuildArsenalConstruction для совместимости)
    // ------------------------------------------------------------------

    /**
     * @param list<string> $deps массив name_en зданий
     * @return list<string> список name_rus отсутствующих
     */
    private function checkDependencies(int $charId, array $deps): array
    {
        if (empty($deps)) {
            return [];
        }
        $missing = [];
        foreach ($deps as $depEn) {
            $bld = $this->buildingModel->where('name_en', $depEn)->first();
            if (!$bld) {
                $missing[] = $depEn;
                continue;
            }
            $owns = $this->characterBuildingModel
                ->where('character_id', $charId)
                ->where('building_id', $bld['id'])
                ->countAllResults();
            if ($owns === 0) {
                $missing[] = BuildingModel::rusName($bld, (string) $depEn);
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_en → нужное количество
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function checkResources(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if (!$row) {
                $missing[$resEn] = ['need' => $need, 'have' => 0, 'name' => $resEn . ' (нет в DB)'];
                continue;
            }
            $charRes = $this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', $row['id'])
                ->first();
            $have = $charRes['quantity'] ?? 0;
            if ($have < $need) {
                $missing[$resEn] = ['need' => $need, 'have' => $have, 'name' => $row['name'] ?? $resEn];
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_eng крафта → нужное количество
     */
    private function checkCraftedItems(int $charId, array $reqs): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $need) {
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = ['need' => $need, 'have' => 0, 'name' => $itemEn . ' (нет в DB)'];
                continue;
            }
            $log = $this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            $have = $log['quantity'] ?? 0;
            if ($have < $need) {
                $missing[$itemEn] = ['need' => $need, 'have' => $have, 'name' => $item['name_rus'] ?? $itemEn];
            }
        }
        return $missing;
    }

    /** @param array<string,int> $reqs */
    private function subtractResources(int $charId, array $reqs): void
    {
        foreach ($reqs as $resEn => $need) {
            $row = $this->resourceModel->getResourceByNameEn($resEn);
            if (!$row) {
                continue;
            }
            $this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', $row['id'])
                ->set('quantity', 'quantity - ' . (int) $need, false)
                ->update();
        }
    }

    /** @param array<string,int> $reqs */
    private function subtractCraftedItems(int $charId, array $reqs): void
    {
        foreach ($reqs as $itemEn => $need) {
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                continue;
            }
            $log = $this->craftedItemsLogModel
                ->where('character_id', $charId)
                ->where('crafted_item_id', $item['id'])
                ->first();
            if (!$log) {
                continue;
            }
            $newQty = $log['quantity'] - $need;
            if ($newQty <= 0) {
                $this->craftedItemsLogModel->delete($log['id']);
            } else {
                $this->craftedItemsLogModel->update($log['id'], ['quantity' => $newQty]);
            }
        }
    }

    /**
     * @param array<string,array{need:int,have:int,name:string}> $missRes
     * @param array<string,array{need:int,have:int,name:string}> $missItems
     */
    private function formatMissing(array $missRes, array $missItems): string
    {
        $lines = [];
        foreach ($missRes as $info) {
            $lines[] = "• {$info['name']}: нужно {$info['need']}, есть {$info['have']}";
        }
        foreach ($missItems as $info) {
            $lines[] = "• {$info['name']}: нужно {$info['need']}, есть {$info['have']}";
        }
        return implode("\n", $lines);
    }

    /**
     * ADR-160: расчёт вынесен в {@see \App\Services\Buildings\BuildDurationService} —
     * единственную точку. Формула жила двумя копиями (здесь и в
     * `GenericBuildingInfoAction::estimateDuration`, где обещается «Строительство
     * займёт ~N минут») и уже разъехалась: тут статы читались дробными, там урезались
     * до целых. Расхождение было в доли минуты, но копии расходятся всегда — вопрос
     * лишь когда и насколько.
     *
     * Потолок счёта статов теперь admin-tunable (`buildings.duration.stat_score_cap`).
     */
    private function calculateDuration(array|\App\Entities\CharacterEntity $character, array $taskRow): int
    {
        return (new \App\Services\Buildings\BuildDurationService())->minutes($character, $taskRow) ?? 0;
    }

    private function sendError(string $text): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
