<?php

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
use Longman\TelegramBot\Request;

/**
 * F2.1 (PoC) — generic-handler начала постройки любого здания.
 *
 * Заменяет 23 копипастных `Start*BuildConstruction.php` /
 * `Build*Construction.php` (~7980 строк дубля). Поведение определяется
 * рецептом из `app/Config/Buildings.php`, ключ строения передаётся
 * через callback_data (`genericStartBuild_Arsenal`).
 *
 * В этом коммите PoC: только для Arsenal, не подключён к
 * `CallbackqueryCommand`. Сравнить с `StartBuildArsenalConstruction.php`
 * можно вручную, прокатать на dev. Подключение — отдельный коммит после
 * прод-validation.
 *
 * Поведенческая совместимость с `StartBuildArsenalConstruction`:
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
    private TaskModel              $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->taskModel              = new TaskModel();
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

        // 4. Проверка лагеря
        $cells = $this->claimedCellModel->where('character_id', $character['id'])->findAll();
        if (empty($cells)) {
            $this->logRejected($character['id'], "BUILD_{$buildingKey}", 'no_camp');
            return $this->sendError('У вас нет лагеря. Сначала разбейте лагерь.');
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

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode($recipe['task_settings']),
        ]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[GenericBuildingAction:{$buildingKey}] транзакция упала для character {$character['id']}");
            return $this->sendError('Ошибка при создании задачи строительства. Попробуйте ещё раз.');
        }

        // 10. Уведомление
        $minutes = $duration;
        $text = "*Строительство {$recipe['name_rus']} начато!*\n\n"
            . "Длительность: ~{$minutes} мин.\n"
            . "По завершении здание будет добавлено на базу.";

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    // ------------------------------------------------------------------
    // helpers (1:1 копия из StartBuildArsenalConstruction для совместимости)
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
                $missing[] = $bld['name_rus'] ?? $depEn;
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

    private function calculateDuration(array $character, array $taskRow): int
    {
        $minD = (int) ($taskRow['min_duration'] ?? 60);
        $maxD = (int) ($taskRow['max_duration'] ?? 180);

        $score = $character['experience'] * 0.3
               + $character['agility']    * 0.3
               + $character['intellect']  * 0.4;
        $ratio = min(1.0, $score / 2000.0);
        $duration = (int) round($maxD - ($maxD - $minD) * $ratio);

        return max($minD, min($maxD, $duration));
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
