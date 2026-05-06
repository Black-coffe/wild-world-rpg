<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Config\CraftRecipes;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * F3.B5 (v0.21.0) — generic action-start для любого крафта.
 *
 * Заменяет копипастные `*CraftActionStart.php` (~250 LOC каждый).
 * Поведение определяется рецептом из `app/Config/CraftRecipes.php`.
 *
 * Callback-формат: `genericCraft_<RecipeKey>_<qty>`
 *   - `genericCraft_Bandage_5` → recipe='Bandage', qty=5
 *   - `genericCraft_Antiseptic`  → recipe='Antiseptic', qty=1 (qty опционален)
 *
 * Логика 1:1 с легаси `*CraftActionStart`:
 *   1. Парсим recipe + qty из callback_data.
 *   2. Lookup recipe в Config\CraftRecipes.
 *   3. Lookup tasks-row по `recipe.task_name` (нужен min/max_duration).
 *   4. Проверка active task этого типа (idempotency).
 *   5. Проверка ресурсов (resources + crafted_items, умноженных на qty).
 *   6. Транзакция: списание ресурсов/items + insert character_tasks
 *      с `task_settings = {recipe: <Key>, quantity: <qty>}`.
 *   7. Telegram-уведомление с фото image_in_progress + временем.
 *
 * Контракт `task_settings.recipe` ключевой — `GenericCraftCompletionHandler`
 * читает именно его (см. v0.16.1 fix). Если контракт нарушится — handler
 * залогирует error, task завершится без выдачи предмета. Поэтому action-side
 * и handler-side мигрируем синхронно в одном батче.
 */
class GenericCraftActionStart extends BaseAction
{
    private CharacterResourceModel $characterResourceModel;
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    // F3.B8: модели для проверки base/buildings (используются опциональными
    // полями recipe.requires_base и recipe.required_buildings).
    private ClaimedCellModel       $claimedCellModel;
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;

    private string $recipeKey = '';
    private int    $quantity  = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();

        // genericCraft_<RecipeKey>_<qty>
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        $this->recipeKey = $parts[1] ?? '';
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $this->quantity = max(1, (int) $parts[2]);
        }
    }

    public function handle(): ServerResponse
    {
        if ($this->recipeKey === '') {
            return $this->sendError('Не указан тип крафта.');
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($this->recipeKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестный рецепт: {$this->recipeKey}");
        }

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь или персонаж не найден.');
        }

        $taskRow = $this->taskModel->where('name', $recipe['task_name'])->first();
        if (!$taskRow) {
            return $this->sendError("Задача '{$recipe['task_name']}' не найдена в базе.");
        }

        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work',
        ])->first();
        if ($activeTask) {
            return $this->sendError(
                "У тебя уже идёт крафт {$recipe['item_name_rus']}! Дождись окончания или прерви," .
                " но при прерывании ресурсы не возвращаются."
            );
        }

        // F3.B8: проверка наличия базы (для крафтов, требующих лагерь).
        if (!empty($recipe['requires_base'])) {
            $hasBase = $this->claimedCellModel->where('character_id', $character['id'])->first();
            if (!$hasBase) {
                $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'no_base');
                return $this->sendError('У вас нет построенной базы (лагеря).');
            }
        }

        // F3.B8: проверка наличия требуемых построек (RoboticsWorkshop, Workshop и т.д.).
        foreach ($recipe['required_buildings'] ?? [] as $buildingNameEn) {
            $building = $this->buildingModel->where('name_en', $buildingNameEn)->first();
            if (!$building) {
                log_message('error', "[GenericCraftActionStart:{$this->recipeKey}] здание '{$buildingNameEn}' не найдено в БД");
                return $this->sendError("Конфигурационная ошибка: здание '{$buildingNameEn}' не найдено в БД.");
            }
            $hasBuilding = $this->characterBuildingModel
                ->where('character_id', $character['id'])
                ->where('building_id', $building['id'])
                ->first();
            if (!$hasBuilding) {
                $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'missing_building', ['building' => $buildingNameEn]);
                $rusName = $building['name_rus'] ?? $buildingNameEn;
                return $this->sendError("У вас нет необходимого здания: *{$rusName}*. Постройте его, чтобы крафтить.");
            }
        }

        // F3.B8: проверка наличия золота (умножается на quantity).
        $goldPerOne   = (int) ($recipe['gold_required'] ?? 0);
        $goldRequired = $goldPerOne * $this->quantity;
        if ($goldRequired > 0 && (int) ($character['gold'] ?? 0) < $goldRequired) {
            $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'insufficient_gold', [
                'need' => $goldRequired,
                'have' => (int) ($character['gold'] ?? 0),
            ]);
            return $this->sendError("Недостаточно золота. Нужно *{$goldRequired}* ед., есть *" . ((int) $character['gold']) . "* ед.");
        }

        // F3.B9: проверка stat-требований персонажа (для weapons).
        // Поля опциональны; для B5-B8 рецептов = 0 (skip check).
        $statChecks = [
            'strength' => (int) ($recipe['required_strength'] ?? 0),
            'agility'  => (int) ($recipe['required_agility']  ?? 0),
            'level'    => (int) ($recipe['required_level']    ?? 0),
        ];
        foreach ($statChecks as $stat => $needed) {
            if ($needed <= 0) {
                continue;
            }
            $have = (int) ($character[$stat] ?? 0);
            if ($have < $needed) {
                $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", "insufficient_{$stat}", [
                    'need' => $needed, 'have' => $have,
                ]);
                $statRus = ['strength' => 'силы', 'agility' => 'ловкости', 'level' => 'уровня'][$stat];
                return $this->sendError("Недостаточно {$statRus}. Нужно *{$needed}*, есть *{$have}*.");
            }
        }

        $missRes   = $this->checkResources($character['id'], $recipe['resources'], $this->quantity);
        $missItems = $this->checkCraftedItems($character['id'], $recipe['crafted_items'] ?? [], $this->quantity);
        if (!empty($missRes) || !empty($missItems)) {
            $this->logRejected(
                $character['id'],
                "CRAFT_{$this->recipeKey}",
                'missing_materials',
                ['missing_resources' => $missRes, 'missing_items' => $missItems, 'qty' => $this->quantity]
            );
            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Транзакция: списание + создание задачи (F0.6 паттерн)
        $db = \Config\Database::connect();
        $db->transStart();

        $this->subtractResources($character['id'], $recipe['resources'], $this->quantity);
        $this->subtractCraftedItems($character['id'], $recipe['crafted_items'] ?? [], $this->quantity);

        // F3.B8: списание золота (если требуется рецептом).
        if ($goldRequired > 0) {
            $this->characterModel->where('id', $character['id'])->decrement('gold', $goldRequired);
        }

        $durationForOne = $this->calculateCraftingDuration($character, $taskRow);
        $totalDuration  = $durationForOne * $this->quantity;

        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $totalDuration . 'M'));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'recipe'   => $this->recipeKey,
                'quantity' => $this->quantity,
            ]),
        ]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[GenericCraftActionStart:{$this->recipeKey}] транзакция упала для character {$character['id']}");
            return $this->sendError('Ошибка при создании задачи крафта. Попробуйте ещё раз.');
        }

        return $this->notifyCraftStarted($recipe, $startTime, $endTime, $this->quantity);
    }

    /**
     * @param array<string,int> $reqs name_rus → количество на 1 шт.
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function checkResources(int $charId, array $reqs, int $qty): array
    {
        $missing = [];
        foreach ($reqs as $resName => $perOne) {
            $need     = $perOne * $qty;
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $charId);
            $have     = $resource['quantity'] ?? 0;
            if (!$resource || $have < $need) {
                $missing[$resName] = ['need' => $need, 'have' => $have, 'name' => $resName];
            }
        }
        return $missing;
    }

    /**
     * @param array<string,int> $reqs name_eng → количество на 1 шт.
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function checkCraftedItems(int $charId, array $reqs, int $qty): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $perOne) {
            $need = $perOne * $qty;
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = ['need' => $need, 'have' => 0, 'name' => $itemEn . ' (не найден)'];
                continue;
            }
            $log  = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId((int) $item['id'], $charId);
            $have = $log['quantity'] ?? 0;
            if ($have < $need) {
                $missing[$itemEn] = ['need' => $need, 'have' => $have, 'name' => $item['name_rus'] ?? $itemEn];
            }
        }
        return $missing;
    }

    /** @param array<string,int> $reqs */
    private function subtractResources(int $charId, array $reqs, int $qty): void
    {
        foreach ($reqs as $resName => $perOne) {
            $need     = $perOne * $qty;
            $resource = $this->characterResourceModel->getResourceByNameAndCharacterId($resName, $charId);
            if (!$resource) {
                continue;
            }
            $charRes = $this->characterResourceModel
                ->where('id_characters', $charId)
                ->where('id_resources', $resource['id'])
                ->first();
            if (!$charRes) {
                continue;
            }
            $newQty = $charRes['quantity'] - $need;
            $this->characterResourceModel->update($charRes['id'], ['quantity' => max(0, $newQty)]);
        }
    }

    /** @param array<string,int> $reqs */
    private function subtractCraftedItems(int $charId, array $reqs, int $qty): void
    {
        foreach ($reqs as $itemEn => $perOne) {
            $need = $perOne * $qty;
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                continue;
            }
            $log = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId((int) $item['id'], $charId);
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
     * Та же формула, что в легаси `*CraftActionStart`:
     * normalized score (exp 0.3 / agi 0.3 / int 0.4 на 1000) и обратная
     * интерполяция между min_duration и max_duration.
     */
    private function calculateCraftingDuration(array|\App\Entities\CharacterEntity $character, array $taskRow): int
    {
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $score    = ($character['experience'] * $expFactor)
                  + ($character['agility']    * $agiFactor)
                  + ($character['intellect']  * $intFactor);
        $maxScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm     = $maxScore > 0 ? $score / $maxScore : 0;

        $minD = (int) $taskRow['min_duration'];
        $maxD = (int) $taskRow['max_duration'];

        $adjusted = $minD + ($maxD - $minD) * (1 - $norm);
        return max($minD, min($maxD, (int) round($adjusted)));
    }

    private function notifyCraftStarted(array $recipe, DateTime $startTime, DateTime $endTime, int $qty): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeStr  = $this->formatMinutes($minutes);

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . "Время крафта: *{$timeStr}* ⏱️\n\n"
            . "После завершения будет добавлено *{$qty}* шт. в твой инвентарь.\n\n"
            . "❗Прерывание задачи = потеря ресурсов!\n\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function formatMinutes(int $totalMinutes): string
    {
        if ($totalMinutes <= 0) {
            return '0 минут';
        }
        $days  = intdiv($totalMinutes, 1440);
        $rem   = $totalMinutes % 1440;
        $hours = intdiv($rem, 60);
        $mins  = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} " . $this->pluralForm($days, ['день', 'дня', 'дней']);
        }
        if ($hours > 0) {
            $parts[] = "{$hours} " . $this->pluralForm($hours, ['час', 'часа', 'часов']);
        }
        if ($mins > 0) {
            $parts[] = "{$mins} " . $this->pluralForm($mins, ['минута', 'минуты', 'минут']);
        }
        return empty($parts) ? '0 минут' : implode(' ', $parts);
    }

    private function pluralForm(int $n, array $forms): string
    {
        $nMod10  = $n % 10;
        $nMod100 = $n % 100;
        if ($nMod100 >= 11 && $nMod100 <= 14) {
            return $forms[2];
        }
        return match ($nMod10) {
            1       => $forms[0],
            2, 3, 4 => $forms[1],
            default => $forms[2],
        };
    }

    private function sendError(string $message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
