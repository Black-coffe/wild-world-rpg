<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Craft;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\ResourcePoolService;
use App\Services\Tasks\ActionScopeService;
use Config\CraftRecipes;
use Config\GameBalance;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
    private CraftedItemsModel      $craftedItemsModel;
    private CraftedItemsLogModel   $craftedItemsLogModel;
    // F3.B8: модели для проверки base/buildings (используются опциональными
    // полями recipe.requires_base и recipe.required_buildings).
    private ClaimedCellModel       $claimedCellModel;
    private BuildingModel          $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;
    // ADR-171: единая точка правды рюкзак+склад — жалоба игрока «сырьё доступно
    // только из рюкзака, даже стоя на складе с тысячами». Проверка и списание
    // обе идут через пул, иначе достаточность считается честно, а списание бы
    // тихо портило данные, добираясь только до рюкзака.
    // `$resourceModel` для resolveResourceId() — уже есть в `BaseAction`, свой не заводим.
    private ResourcePoolService    $resourcePool;

    private string $recipeKey = '';
    private int    $quantity  = 1;
    private GameBalance $cfg;
    private BuildingEffectsService $buildingEffects;
    private GameSettingsService $gameSettings;
    private ActionScopeService $scope;
    /** ADR-158: разбивка длительности последнего расчёта — для строки правды. */
    private ?\App\Services\Craft\CraftDurationBreakdown $durationBreakdown = null;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->claimedCellModel       = new ClaimedCellModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->resourcePool           = new ResourcePoolService();
        $this->cfg                    = config('GameBalance');
        $this->buildingEffects        = new BuildingEffectsService(
            $this->characterBuildingModel,
            $this->buildingModel,
        );
        $this->gameSettings           = new GameSettingsService();
        $this->scope                  = new ActionScopeService();

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

        // ADR-167: 🔒-крафт (parallel_execution_allowed=0) не стартует поверх другого
        // 🔒-дела. Проверка стоит ДО очереди и до списания ресурсов — иначе игрок
        // терял бы сырьё в очередь, которая всё равно не может пойти.
        // Свой же рецепт помехой не считается: ниже он уйдёт в очередь (queued),
        // а не запустится вторым — очередь v0.51.129 остаётся рабочей.
        $taskNameRus = is_string($taskRow['name_rus'] ?? null) ? $taskRow['name_rus'] : '';
        $conflict    = $this->exclusiveConflictText(
            (int) $character['id'],
            $taskRow['parallel_execution_allowed'] ?? 1,
            $taskNameRus,
            (int) $taskRow['id'],
        );
        if ($conflict !== null) {
            $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'exclusive_task_busy');
            return $this->sendError($conflict);
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
                $rusName = BuildingModel::rusName($building, is_string($buildingNameEn) ? $buildingNameEn : '');
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
                    $rusNameForLevel = BuildingModel::rusName($building, (string) $buildingNameEn);
                    return $this->sendError("Здание *{$rusNameForLevel}* должно быть уровня *{$needLevel}* (сейчас *{$haveLevel}*). Прокачай и возвращайся.");
                }
            }
        }

        // S17 (v0.51.199, ADR-026 extension): проверка наличия non-consumable
        // crafted_items в инвентаре (например, ProfessionalWorkbench для T3 weapons).
        // В отличие от recipe.crafted_items (расходные компоненты со списанием),
        // recipe.required_crafted_items — gate-проверка без decrement: просто
        // "у чара есть N штук в crafted_items_log". Reusable для tier-gated
        // крафтов (S17-S20 + любые будущие predmety-as-gates).
        $requiredCraftedItemsRaw = $recipe['required_crafted_items'] ?? [];
        $requiredCraftedItems    = is_array($requiredCraftedItemsRaw) ? $requiredCraftedItemsRaw : [];
        $missingRequiredItems    = $this->checkRequiredCraftedItems(
            (int) $character['id'],
            $requiredCraftedItems,
        );
        if (!empty($missingRequiredItems)) {
            $firstMissing = reset($missingRequiredItems);
            $missingName  = $firstMissing['name'];
            $this->logRejected(
                $character['id'],
                "CRAFT_{$this->recipeKey}",
                'missing_required_crafted_item',
                ['missing' => $missingRequiredItems],
            );
            return $this->sendError("Нужно иметь *{$missingName}* в инвентаре. Скрафти его и возвращайся.");
        }

        // S25 (ADR-029): quest-gate — рецепт заблокирован пока не завершён нужный
        // quest (StrategicCapture<X>). required_quest = quests.title_en.
        $requiredQuest = isset($recipe['required_quest']) && is_string($recipe['required_quest'])
            ? $recipe['required_quest']
            : '';
        if ($requiredQuest !== '' && !$this->isQuestCompleted((int) $character['id'], $requiredQuest)) {
            $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'required_quest_incomplete', [
                'quest' => $requiredQuest,
            ]);
            return $this->sendError("Этот рецепт откроется после захвата стратегического объекта (квест ещё не завершён).");
        }

        // S25 (ADR-029): faction-gate — только член нужной фракции (true
        // faction-exclusive). required_faction = character_factions.faction_id.
        $requiredFaction = isset($recipe['required_faction']) && is_numeric($recipe['required_faction'])
            ? (int) $recipe['required_faction']
            : 0;
        if ($requiredFaction > 0 && $this->characterFactionId((int) $character['id']) !== $requiredFaction) {
            $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'required_faction_mismatch', [
                'need' => $requiredFaction,
            ]);
            return $this->sendError("Это фракционное оружие может скрафтить только член соответствующей фракции.");
        }

        // S28 (ADR-032): seasonal-gate — рецепт доступен только когда активен его
        // сезон (SeasonalCraftService, детерминированно от anchor+cycle). Defense-
        // in-depth: меню показывает только активные, но гейт страхует от прямого callback.
        $requiredSeason = isset($recipe['required_season']) && is_string($recipe['required_season'])
            ? $recipe['required_season']
            : '';
        if ($requiredSeason !== '') {
            $seasonalService = new \App\Services\World\SeasonalCraftService();
            if (!$seasonalService->isSeasonActive($requiredSeason)) {
                $this->logRejected($character['id'], "CRAFT_{$this->recipeKey}", 'season_inactive', [
                    'required_season' => $requiredSeason,
                ]);
                $label = $seasonalService->getSeasonLabel($requiredSeason);
                $labelTxt = $label !== '' ? "«{$label}»" : 'свой сезон';
                return $this->sendError("Этот сезонный рецепт сейчас недоступен — вернётся в сезон {$labelTxt}.");
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

            // ADR-158: точный список недостающего сервер уже посчитал — раньше он
            // молча уходил в лог, а игрок получал «Недостаточно ресурсов» без единой
            // подсказки и кнопки. 137 из 144 прод-отказов — нехватка сырья, поэтому
            // экран прежде всего отвечает «где это добывается».
            $shortage = new \App\Services\Craft\CraftShortageService();
            if ($shortage->isEnabled()) {
                $screen = $shortage->describe($character, $missRes, $missItems, $this->quantity, $recipe);
                Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

                return Request::sendMessage([
                    'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'         => $screen['text'],
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($screen['keyboard']),
                ]);
            }

            return $this->sendError("Недостаточно ресурсов для крафта {$this->quantity} шт.");
        }

        // Транзакция: списание + создание задачи (F0.6 паттерн)
        $db = \Config\Database::connect();
        $db->transStart();

        // ADR-171 race guard: `checkResources()` подтвердил достаточность ДО транзакции,
        // но между проверкой и списанием мог проскочить параллельный запрос (второй крафт,
        // сдача на склад и т.д.) и забрать тот же остаток. `consumeByName()` в этом случае
        // не списывает ничего и бросает — раньше это глушилось здесь же логом и крафт
        // стартовал, ничего не заплатив за ресурс (хуже прежнего silent-clamp «недоплатил»).
        // Теперь гонка обязана ломать старт целиком: откат транзакции, честный ответ игроку,
        // задача не создаётся — тот же путь отказа, что и ниже у транзакции создания задачи.
        try {
            $this->subtractResources($character['id'], $recipe['resources'], $this->quantity);
            $this->subtractCraftedItems($character['id'], $recipe['crafted_items'] ?? [], $this->quantity);
        } catch (\RuntimeException $e) {
            $db->transRollback();
            log_message('error', "[GenericCraftActionStart:{$this->recipeKey}] пул словил гонку при списании для character {$character['id']}: " . $e->getMessage());
            return $this->sendError('Сырьё разошлось, пока ты выбирал — проверь запас и попробуй ещё раз.');
        }

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

        // ADR-143: занятость берём из флага задачи — всегда совпадает с реальным
        // блокированием движения/добычи (GenericCraftActionStart не зовёт guard сам,
        // но MoveCharacterToDirectionAction/GatherAction/MarchAction читают этот флаг).
        $background = $this->scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);

        if ($isQueued) {
            return $this->notifyCraftQueued($recipe, $sameRecipeCount + 1, $this->quantity, $insertedId, $background);
        }
        return $this->notifyCraftStarted($recipe, $startTime, $endTime, $this->quantity, $background);
    }

    /**
     * Рахує distinct task_ids з активних/чергованих **крафт-задач** для character.
     * Кожен такий task_id = окремий "slot крафта". 0..craftMaxConcurrentSlots допустимо.
     *
     * v0.51.265 (Arseny report 2026-05-26): JOIN tasks + WHERE tasks.type='craft' —
     * раніше лічило ВСІ active задачі (включно з робот-добувачем = tasks.type='optionally'),
     * через що повідомлення «Все 3 слота крафта заняты» брехало про gather-задачу.
     * Тепер слот крафта = лише крафт (user-вердикт «крафт 100%»). Інші типи
     * (`optionally` робот-gather/explore, `building` стройка, `quest`) — свої циклы,
     * слот крафта не займають.
     */
    private function countDistinctActiveSlots(int $characterId): int
    {
        $rows = $this->characterTaskModel
            ->select('character_tasks.task_id')
            ->distinct()
            ->join('tasks', 'tasks.id = character_tasks.task_id', 'inner')
            ->where('character_tasks.character_id', $characterId)
            ->whereIn('character_tasks.status', ['in_work', 'queued'])
            ->where('tasks.type', 'craft')
            ->findAll();
        return count($rows);
    }

    /**
     * ADR-171: достаточность считается по пулу рюкзак+склад (когда игрок на базе),
     * не только по рюкзаку. `storage`/`pooled` в возврате нужны экрану нехватки —
     * он обязан сказать «ждёт на складе», даже если сейчас игрок не на базе и
     * склад в `have` не засчитан.
     *
     * @param array<string,int> $reqs name_rus → количество на 1 шт.
     * @return array<string,array{need:int,have:int,name:string,storage:int,pooled:bool}>
     */
    private function checkResources(int $charId, array $reqs, int $qty): array
    {
        $missing = [];
        foreach ($reqs as $resName => $perOne) {
            $need       = $perOne * $qty;
            $resourceId = $this->resolveResourceId($resName);
            if ($resourceId === null) {
                $missing[$resName] = ['need' => $need, 'have' => 0, 'name' => $resName, 'storage' => 0, 'pooled' => false];
                continue;
            }

            $breakdown = $this->resourcePool->breakdown($charId, $resourceId);
            $have      = $breakdown['backpack'] + ($breakdown['pooled'] ? $breakdown['storage'] : 0);
            if ($have < $need) {
                $missing[$resName] = [
                    'need'    => $need,
                    'have'    => $have,
                    'name'    => $resName,
                    'storage' => $breakdown['storage'],
                    'pooled'  => $breakdown['pooled'],
                ];
            }
        }
        return $missing;
    }

    private function resolveResourceId(string $resName): ?int
    {
        $resource = $this->resourceModel->getResourceByName($resName);
        if ($resource === null) {
            return null;
        }
        $id = is_object($resource) ? ($resource->id ?? null) : ($resource['id'] ?? null);

        return is_numeric($id) ? (int) $id : null;
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

    /**
     * S17 (ADR-026 extension) — non-consumable gate check для crafted_items.
     * В отличие от checkCraftedItems (расходные компоненты, qty умножается),
     * здесь требование фиксированное (N штук должно быть в инвентаре),
     * без списания. Reusable для tier-gated крафтов (ProfessionalWorkbench и т.д.).
     *
     * Recipe field — раскрытое значение из `Config\CraftRecipes`, тип mixed
     * (т.к. recipe array decode'ится из untyped storage). Caller отвечает
     * за is_array() narrow до вызова.
     *
     * @param array<string|int,mixed> $requiredItems name_eng => need_qty
     * @return array<string,array{need:int,have:int,name:string}>
     */
    private function checkRequiredCraftedItems(int $charId, array $requiredItems): array
    {
        $missing = [];
        foreach ($requiredItems as $itemEn => $need) {
            if (!is_string($itemEn) || $itemEn === '') {
                continue;
            }
            $needInt = is_numeric($need) ? (int) $need : 0;
            if ($needInt <= 0) {
                continue;
            }
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = ['need' => $needInt, 'have' => 0, 'name' => $itemEn . ' (не найден)'];
                continue;
            }
            $log  = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId((int) $item['id'], $charId);
            $have = $log['quantity'] ?? 0;
            if ($have < $needInt) {
                $missing[$itemEn] = ['need' => $needInt, 'have' => (int) $have, 'name' => $item['name_rus'] ?? $itemEn];
            }
        }
        return $missing;
    }

    /**
     * S25 (ADR-029): завершён ли у персонажа quest по `quests.title_en`
     * (есть quest_steps с is_completed=1). Gate для faction weapons.
     */
    private function isQuestCompleted(int $charId, string $titleEn): bool
    {
        $db    = \Config\Database::connect();
        $query = $db->table('quest_steps qs')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('q.title_en', $titleEn)
            ->where('qs.character_id', $charId)
            ->where('qs.is_completed', 1)
            ->get();
        return $query !== false && $query->getFirstRow('array') !== null;
    }

    /**
     * S25 (ADR-029): faction_id персонажа (0 если нет записи / Нейтрал).
     */
    private function characterFactionId(int $charId): int
    {
        $db    = \Config\Database::connect();
        $query = $db->table('character_factions')
            ->where('character_id', $charId)
            ->get();
        $row = $query !== false ? $query->getFirstRow('array') : null;
        return is_array($row) && isset($row['faction_id']) && is_numeric($row['faction_id'])
            ? (int) $row['faction_id']
            : 0;
    }

    /**
     * ADR-171: списание идёт через тот же пул, что и проверка достаточности —
     * рюкзак сначала, остаток со склада. Работает внутри уже открытой транзакции
     * старта (`consume()` своей не открывает). `checkResources()` уже подтвердил
     * достаточность перед вызовом; `RuntimeException` здесь возможен только при
     * гонке (параллельный запрос успел списать то же самое между проверкой и
     * транзакцией) — намеренно НЕ ловим её тут: пусть поднимется вызывающему,
     * который откатывает транзакцию целиком (см. `handle()`). Глотать её здесь
     * означало бы запустить крафт, не заплатив за ресурс вовсе.
     *
     * @param array<string,int> $reqs
     * @throws \RuntimeException при гонке за тот же остаток
     */
    private function subtractResources(int $charId, array $reqs, int $qty): void
    {
        foreach ($reqs as $resName => $perOne) {
            $need = $perOne * $qty;
            if ($need < 1) {
                continue;
            }
            $this->resourcePool->consumeByName($charId, $resName, $need);
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
        // ADR-158: формула вынесена в CraftDurationService. До этого её копия жила
        // в GenericCraftCompletionHandler и уже разъехалась — активация из очереди
        // считала время БЕЗ единого множителя, из-за чего вторая вещь в очереди
        // делалась дольше первой. Разбивку сохраняем для строки правды в уведомлении.
        $this->durationBreakdown = (new \App\Services\Craft\CraftDurationService($this->gameSettings, $this->buildingEffects))
            ->forOne($character, $taskRow, $recipe);

        return $this->durationBreakdown->minutes;
    }

    /**
     * v0.51.129: notification для queued task. Показує queue position + qty +
     * cancel button з callback `cancelQueued_<task_id>` для refund.
     */
    private function notifyCraftQueued(array $recipe, int $queuePosition, int $qty, int $charTaskId, bool $background): ServerResponse
    {
        $text = "*В очередь поставлено:* {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . $this->scope->scopeLine(ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "📋 Позиция в очереди: *#{$queuePosition}*\n"
            . "Начнётся автоматически после завершения активного крафта.\n\n"
            . "❗Ресурсы уже списаны. Отмена очереди вернёт их.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ Отменить из очереди', 'callback_data' => "cancelQueued_{$charTaskId}"]],
                [['text' => '📋 Очередь крафта', 'callback_data' => 'craftQueue']],
            ],
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

    private function notifyCraftStarted(array $recipe, DateTime $startTime, DateTime $endTime, int $qty, bool $background): ServerResponse
    {
        $interval = $startTime->diff($endTime);
        $minutes  = $interval->days * 1440 + $interval->h * 60 + $interval->i;
        $timeStr  = $this->formatMinutes($minutes);

        // ADR-158 «строка правды»: свободный стек множителей достигает ×0.22, но был
        // полностью невидим — игрок с −78% видел только итоговое число, читал крафт
        // как медленный и просил ускорение, которое у него уже есть. Показываем и
        // базу, и за счёт чего быстрее. Без бонусов строка не отличается от прежней.
        $timeBlock = "Время крафта: *{$timeStr}* ⏱️";
        if ($this->durationBreakdown !== null
            && $this->durationBreakdown->hasBonuses()
            && (bool) $this->gameSettings->get('craft.duration_breakdown.enabled', true)
        ) {
            $timeBlock = $this->durationBreakdown->truthLine($qty);
        }

        $text = "*Процесс крафта запущен*\n\n"
            . "Ты создаёшь: {$recipe['start_caption_name']} x{$qty} шт.\n\n"
            . $this->scope->startedBlock(ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . $timeBlock . "\n\n"
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
