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
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\GameSettings\GameSettingsService;
use Config\CraftRecipes;
use Config\GameBalance;
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
 *
 * v0.51.129 (community idea #1) — craft queue:
 *   - Замість блокування same-recipe duplicate task — створюється queued task.
 *   - Slot cap (`craftMaxConcurrentSlots` = 3 default): max distinct active+queued
 *     recipes per character. Понад — reject.
 *   - Per-recipe queue cap (`craftMaxQueuePerRecipe` = 10 default): max tasks
 *     для одного recipe (active + queued). Понад — reject.
 *   - Resources списуються upfront у обох path (in_work + queued).
 *   - Queued task: status='queued', start_time=created_at placeholder, end_time=NULL.
 *     Activate коли GenericCraftCompletionHandler закінчує active task для same recipe.
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
    private GameBalance $cfg;
    private BuildingEffectsService $buildingEffects;
    private GameSettingsService $gameSettings;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->cfg                    = config('GameBalance');
        $this->buildingEffects        = new BuildingEffectsService(
            $this->characterBuildingModel,
            $this->buildingModel,
        );
        $this->gameSettings           = new GameSettingsService();

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

        // v0.51.129: queue logic. Active task для цього recipe → НЕ блок'уємо,
        // а додаємо у queue (status='queued'). Reject лише при перевищенні
        // queue cap або slot cap.
        $activeTask = $this->characterTaskModel->where([
            'character_id' => $character['id'],
            'task_id'      => $taskRow['id'],
            'status'       => 'in_work',
        ])->first();

        // Per-recipe queue cap: count active+queued tasks для same recipe
        $sameRecipeCount = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->whereIn('status', ['in_work', 'queued'])
            ->countAllResults();
        if ($sameRecipeCount >= $this->cfg->craftMaxQueuePerRecipe) {
            return $this->sendError(
                "Очередь крафта *{$recipe['item_name_rus']}* заполнена ("
                . "{$this->cfg->craftMaxQueuePerRecipe} макс.). Дождись завершения или отмени один."
            );
        }

        // Slot cap: count distinct task_ids with active+queued tasks. Якщо new
        // recipe (no active+queued for this taskRow yet) AND already at slot cap → reject.
        if ($sameRecipeCount === 0) {
            $distinctSlotsUsed = $this->countDistinctActiveSlots($character['id']);
            if ($distinctSlotsUsed >= $this->cfg->craftMaxConcurrentSlots) {
                return $this->sendError(
                    "Все *{$this->cfg->craftMaxConcurrentSlots}* слота крафта заняты. "
                    . "Дождись завершения одного из активных или отмени запас."
                );
            }
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
        // S16 (ADR-026): дополнительно — level-aware check через recipe.required_building_levels.
        $requiredBuildingLevels = (isset($recipe['required_building_levels']) && is_array($recipe['required_building_levels']))
            ? $recipe['required_building_levels']
            : [];
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
            // S16: level-aware gate (новое поле). $buildingNameEn is mixed (recipe value);
            // is_string() narrow для безопасного offset lookup в $requiredBuildingLevels.
            $needLevelRaw = is_string($buildingNameEn) && isset($requiredBuildingLevels[$buildingNameEn])
                ? $requiredBuildingLevels[$buildingNameEn]
                : 0;
            $needLevel    = is_numeric($needLevelRaw) ? (int) $needLevelRaw : 0;
            if ($needLevel > 0) {
                $haveLevelRaw = is_array($hasBuilding) && isset($hasBuilding['level']) ? $hasBuilding['level'] : 0;
                $haveLevel    = is_numeric($haveLevelRaw) ? (int) $haveLevelRaw : 0;
                if ($haveLevel < $needLevel) {
                    $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'insufficient_building_level', [
                        'building' => $buildingNameEn,
                        'need'     => $needLevel,
                        'have'     => $haveLevel,
                    ]);
                    $rusRaw = is_array($building) && isset($building['name_rus']) ? $building['name_rus'] : null;
                    $rusNameForLevel = is_string($rusRaw) && $rusRaw !== '' ? $rusRaw : $buildingNameEn;
                    return $this->sendError("Здание *{$rusNameForLevel}* должно быть уровня *{$needLevel}* (сейчас *{$haveLevel}*). Прокачай и возвращайся.");
                }
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
        //
        // S16 (ADR-026): если recipe.required_level_setting_key задан — берём
        // значение из GameSettings (admin-tunable), fallback на recipe.required_level.
        $levelRequired = (int) ($recipe['required_level'] ?? 0);
        $levelSettingKey = isset($recipe['required_level_setting_key']) && is_string($recipe['required_level_setting_key'])
            ? $recipe['required_level_setting_key']
            : null;
        if ($levelSettingKey !== null) {
            $tuned = $this->gameSettings->get($levelSettingKey, $levelRequired);
            if (is_numeric($tuned)) {
                $levelRequired = (int) $tuned;
            }
        }
        $statChecks = [
            'strength' => (int) ($recipe['required_strength'] ?? 0),
            'agility'  => (int) ($recipe['required_agility']  ?? 0),
            'level'    => $levelRequired,
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

        $durationForOne = $this->calculateCraftingDuration($character, $taskRow, $recipe);
        $totalDuration  = $durationForOne * $this->quantity;

        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $totalDuration . 'M'));

        // v0.51.129: queue path якщо вже active task для recipe.
        $isQueued = $activeTask !== null;

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            // Queued tasks: start_time = createdAt placeholder, end_time = NULL
            // (Worker.php skip'ить status!=in_work). При activate dequeue handler
            // оновить start_time=now, end_time=now+dur, status=in_work.
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $isQueued ? null : $endTime->format('Y-m-d H:i:s'),
            'status'           => $isQueued ? 'queued' : 'in_work',
            'task_settings'    => json_encode([
                'recipe'   => $this->recipeKey,
                'quantity' => $this->quantity,
            ]),
        ]);
        $insertedId = (int) $this->characterTaskModel->getInsertID();

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[GenericCraftActionStart:{$this->recipeKey}] транзакция упала для character {$character['id']}");
            return $this->sendError('Ошибка при создании задачи крафта. Попробуйте ещё раз.');
        }

        if ($isQueued) {
            return $this->notifyCraftQueued($recipe, $sameRecipeCount + 1, $this->quantity, $insertedId);
        }
        return $this->notifyCraftStarted($recipe, $startTime, $endTime, $this->quantity);
    }

    /**
     * v0.51.129: рахує distinct task_ids з active або queued tasks для character.
     * Кожен такий task_id = окремий "slot". 0..craftMaxConcurrentSlots допустимо.
     */
    private function countDistinctActiveSlots(int $characterId): int
    {
        $rows = $this->characterTaskModel
            ->select('task_id')
            ->distinct()
            ->where('character_id', $characterId)
            ->whereIn('status', ['in_work', 'queued'])
            ->findAll();
        return count($rows);
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
     *
     * S11 (v0.51.193): після char-stats формули — applied Workshop level
     * multiplier (live-tunable через GameSettings, дефолт L1=1.0 / L2=0.90 /
     * L3+ cascade на 0.75). Min duration = 1 minute (clamp).
     * S13a (v0.51.195): додаткове stacking з $recipe['boost_building_time']
     * (e.g. 'Laboratory' для medical recipes). Multiplicative з Workshop.
     *
     * @param array<string,mixed> $recipe
     */
    private function calculateCraftingDuration(array|\App\Entities\CharacterEntity $character, array $taskRow, array $recipe = []): int
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

        // S16 (ADR-026): live-tunable duration override через GameSettings.
        // recipe.duration_override_setting_key хранит ЧАСЫ; конвертируем в минуты,
        // используем как min=max (overriding tasks.min_duration/max_duration).
        $durationSettingKey = isset($recipe['duration_override_setting_key']) && is_string($recipe['duration_override_setting_key'])
            ? $recipe['duration_override_setting_key']
            : null;
        if ($durationSettingKey !== null) {
            $tunedHours = $this->gameSettings->get($durationSettingKey, null);
            if (is_numeric($tunedHours) && (int) $tunedHours > 0) {
                $minutes = (int) $tunedHours * 60;
                $minD = $minutes;
                $maxD = $minutes;
            }
        }

        $adjusted = $minD + ($maxD - $minD) * (1 - $norm);
        $baseDuration = max($minD, min($maxD, (int) round($adjusted)));

        $extraBuilding = isset($recipe['boost_building_time']) && is_string($recipe['boost_building_time'])
            ? $recipe['boost_building_time']
            : null;
        $multiplier = $this->buildingEffects->getCraftTimeMultiplier((int)($character['id'] ?? 0), $extraBuilding);
        return max(1, (int) round($baseDuration * $multiplier));
    }

    /**
     * v0.51.129: notification для queued task. Показує queue position + qty +
     * cancel button з callback `cancelQueued_<task_id>` для refund.
     */
    private function notifyCraftQueued(array $recipe, int $queuePosition, int $qty, int $charTaskId): ServerResponse
    {
        $text = "*В очередь поставлено:* {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . "📋 Позиция в очереди: *#{$queuePosition}*\n"
            . "Начнётся автоматически после завершения активного крафта.\n\n"
            . "❗Ресурсы уже списаны. Отмена очереди вернёт их.";

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '❌ Отменить из очереди', 'callback_data' => "cancelQueued_{$charTaskId}"],
            ]],
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'        => Request::encodeFile(base_url($recipe['image_in_progress'])),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
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

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
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
