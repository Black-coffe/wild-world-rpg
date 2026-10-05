<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\BuildingEffects\BuildingEffectsService;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Player\DroneService;
use App\Services\Player\ResourcePoolService;
use App\Services\Tasks\ActionScopeService;
use App\Services\Tasks\ActiveTasksService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\CraftRecipes;
use Config\Database;
use DateInterval;
use DateTime;

/**
 * W2.N3-01 (ADR-190) — старт крафта без Telegram: гейты, сырьё из пула рюкзак+склад (ADR-171),
 * длительность ({@see CraftDurationService}) и строка `character_tasks` `in_work|queued`.
 * Логика и тексты отказов перенесены 1:1 из `GenericCraftActionStart`; handler бота теперь только
 * рендерит исходы, веб (story 03) зовёт те же {@see preview()} / {@see start()}.
 *
 * Атомарность (ADR-181): лимиты очереди и слотов перепроверяются под `SELECT … FOR UPDATE` строки
 * персонажа в той же транзакции, что и вставка; золото — `decrementIfAtLeast`, предметы-ингредиенты —
 * `decrementIfAtLeast(deleteWhenEmpty)`. Разбивка списания рюкзак/склад — в `task_settings.consumed`
 * (её читает отмена очереди).
 *
 * Отказ — `{code, message, log}`: `message` — готовый Markdown-текст (как слал бот), `log` —
 * причина и данные для `action_log` (пишет рендерер: ему нужен chat_id).
 *
 * @phpstan-type Log array{reason:string, extra:array<string,mixed>}
 * @phpstan-type Refusal array{code:string, message:string, log:?Log}
 * @phpstan-type Missing array<string, array{need:int, have:int, name:string, storage?:int, pooled?:bool}>
 * @phpstan-type Consumed array{gold:int, resources:array<string,int>, crafted_items:array<string,int>}
 * @phpstan-type Batch array{qty:int, gold:int, resources:array<string,int>, crafted_items:array<string,int>, minutes_total:int}
 */
class CraftOrderService
{
    public const STARTED           = 'started';
    public const QUEUED            = 'queued';
    public const NO_RECIPE         = 'no_recipe';
    public const NO_CHARACTER      = 'no_character';
    public const NO_TASK           = 'no_task';
    public const BUSY              = 'exclusive_task_busy';
    public const QUEUE_FULL        = 'queue_full';
    public const SLOTS_FULL        = 'slots_full';
    public const NO_BASE           = 'no_base';
    public const CONFIG_ERROR      = 'config_error';
    public const NO_BUILDING       = 'missing_building';
    public const BUILDING_LEVEL    = 'insufficient_building_level';
    public const NO_REQUIRED_ITEM  = 'missing_required_crafted_item';
    public const QUEST             = 'required_quest_incomplete';
    public const FACTION           = 'required_faction_mismatch';
    public const SEASON            = 'season_inactive';
    public const FEATURE_OFF       = 'recipe_feature_disabled';
    public const NO_GOLD           = 'insufficient_gold';
    public const NO_STAT           = 'insufficient_stat';
    public const MISSING_MATERIALS = 'missing_materials';
    /** craft-batch-price-confirm: крупная партия без явного подтверждения — ничего не списано, в ответе `batch`. */
    public const CONFIRM_REQUIRED  = 'confirm_required';
    public const RACE              = 'race';
    public const TX_FAILED         = 'tx_failed';

    public const KEY_MAX_PER_RECIPE = 'craft.queue.max_per_recipe';
    public const KEY_MAX_SLOTS      = 'craft.queue.max_slots';

    /** Дефолты — значения seed-миграции `SeedCraftQueueLimitSettings` (прежние `Config\GameBalance`). */
    private const DEFAULT_MAX_PER_RECIPE = 10;
    private const DEFAULT_MAX_SLOTS      = 3;

    /** Рыбные блюда костра — за флагом фичи; единственный список, экран костра бота читает его отсюда. */
    private const FISH_FLAG = 'cooking.fish_dishes.enabled';

    /** @var list<string> */
    public const FISH_RECIPES = ['FishSoup', 'GrilledFish', 'FishPreserve'];

    private const RACE_TEXT = 'Сырьё разошлось, пока ты выбирал — проверь запас и попробуй ещё раз.';

    private ResourcePoolService $resourcePool;
    private ResourceModel $resourceModel;
    private GameSettingsService $gameSettings;
    private CraftedItemsModel $craftedItemsModel;
    private CraftedItemsLogModel $craftedItemsLogModel;
    private BuildingModel $buildingModel;
    private CharacterBuildingModel $characterBuildingModel;
    private CharacterTaskModel $characterTaskModel;

    public function __construct(
        ?ResourcePoolService $resourcePool = null,
        ?ResourceModel $resourceModel = null,
        ?GameSettingsService $gameSettings = null
    ) {
        $this->resourcePool           = $resourcePool ?? new ResourcePoolService();
        $this->resourceModel          = $resourceModel ?? new ResourceModel();
        $this->gameSettings           = $gameSettings ?? new GameSettingsService();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->characterTaskModel     = new CharacterTaskModel();
    }

    /**
     * Карточка рецепта без записи: сырьё (пул), золото, время, сколько можно поставить и первый
     * отказ гейтов. `max_qty` — сколько штук хватает сырья/компонентов/золота (0, если гейт отказал:
     * очередь/слоты/эксклюзив и т. п.).
     *
     * @return array{ok:bool, code:string, message:string, recipe:array{key:string,name:string,icon:string,output_type:string},
     *     resources:list<array{name:string,need:int,have:int}>, items:list<array{name:string,need:int,have:int}>, gold:int,
     *     minutes_one:int, minutes_total:int, max_qty:int, needs_confirm:bool, queue_pos:int, gates:list<array{code:string,message:string}>}
     */
    public function preview(int $characterId, string $recipeKey, int $qty): array
    {
        $qty    = max(1, $qty);
        $recipe = $this->recipe($recipeKey);
        $out    = [
            'ok'            => false,
            'code'          => self::NO_RECIPE,
            'message'       => "Неизвестный рецепт: {$recipeKey}",
            'recipe'        => ['key' => $recipeKey, 'name' => '', 'icon' => '', 'output_type' => 'item'],
            'resources'     => [],
            'items'         => [],
            'gold'          => 0,
            'minutes_one'   => 0,
            'minutes_total' => 0,
            'max_qty'       => 0,
            'needs_confirm' => false,
            'queue_pos'     => 0,
            'gates'         => [],
        ];
        if ($recipe === null) {
            return $out;
        }
        $out['recipe'] = [
            'key'         => $recipeKey,
            'name'        => $this->str($recipe, 'item_name_rus'),
            'icon'        => $this->str($recipe, 'icon_emoji'),
            'output_type' => $this->str($recipe, 'output_type') !== '' ? $this->str($recipe, 'output_type') : 'item',
        ];

        $character = $this->character($characterId);
        if ($character === null) {
            return ['code' => self::NO_CHARACTER, 'message' => 'Пользователь или персонаж не найден.'] + $out;
        }
        $taskRow = $this->taskRow($recipe);
        if ($taskRow === null) {
            return ['code' => self::NO_TASK, 'message' => "Задача '{$this->str($recipe, 'task_name')}' не найдена в базе."] + $out;
        }

        $resources = $this->intMap($recipe['resources'] ?? []);
        $items     = $this->intMap($recipe['crafted_items'] ?? []);
        $goldOne   = $this->recipeIntField($recipe, 'gold_required');

        $maxQty = PHP_INT_MAX;
        foreach ($resources as $name => $perOne) {
            $resourceId = $this->resolveResourceId($name);
            $have       = 0;
            if ($resourceId !== null) {
                $b    = $this->resourcePool->breakdown($characterId, $resourceId);
                $have = $b['backpack'] + ($b['pooled'] ? $b['storage'] : 0);
            }
            $out['resources'][] = ['name' => $name, 'need' => $perOne * $qty, 'have' => $have];
            if ($perOne > 0) {
                $maxQty = min($maxQty, intdiv($have, $perOne));
            }
        }
        foreach ($items as $itemEn => $perOne) {
            [$have, $name]  = $this->craftedItemHave($characterId, $itemEn);
            $out['items'][] = ['name' => $name, 'need' => $perOne * $qty, 'have' => $have];
            if ($perOne > 0) {
                $maxQty = min($maxQty, intdiv($have, $perOne));
            }
        }
        if ($goldOne > 0) {
            $maxQty = min($maxQty, intdiv($this->characterIntField($character, 'gold'), $goldOne));
        }
        if ($maxQty === PHP_INT_MAX) {
            $maxQty = max(CraftCardHelper::STEPS);
        }

        $breakdown            = (new CraftDurationService($this->gameSettings, $this->buildingEffects()))->forOne($character, $taskRow, $recipe);
        $out['gold']          = $goldOne * $qty;
        $out['needs_confirm'] = (new CraftBatchConfirmPolicy($this->gameSettings))->needsConfirm($qty, $out['gold']);
        $out['minutes_one']   = $breakdown->minutes;
        $out['minutes_total'] = $breakdown->minutes * $qty;
        $out['queue_pos']     = $this->sameRecipeCount($characterId, $this->intField($taskRow, 'id')) + 1;

        $gate = $this->gateError($recipeKey, $recipe, $character, $taskRow, $qty);
        if ($gate !== null) {
            $out['gates'] = [['code' => $gate['code'], 'message' => $gate['message']]];

            return ['code' => $gate['code'], 'message' => $gate['message'], 'max_qty' => 0] + $out;
        }
        $out['max_qty'] = $maxQty;
        if ($maxQty < $qty) {
            return ['code' => self::MISSING_MATERIALS, 'message' => "Недостаточно ресурсов для крафта {$qty} шт."] + $out;
        }

        return ['ok' => true, 'code' => self::STARTED, 'message' => ''] + $out;
    }

    /**
     * Старт крафта: гейты → сырьё → транзакция (блокировка персонажа, перепроверка лимитов,
     * списание, вставка). Успех — `code` STARTED или QUEUED.
     *
     * @return array{ok:bool, code:string, message:string, log:?Log, char_task_id:int, status:string, started_at:?string,
     *     ends_at:?string, minutes_total:int, queue_pos:int, background:bool, breakdown:?CraftDurationBreakdown,
     *     missing_resources:Missing, missing_items:Missing, consumed:Consumed, batch:?Batch}
     *
     * `$confirmed = false` — безопасный дефолт: партия выше порога {@see CraftBatchConfirmPolicy} без явного
     * подтверждения клиента получает `CONFIRM_REQUIRED` с итогом `batch` и ничего не списывает. Правило
     * живёт здесь, а не в рендерерах: старт зовут и бот, и `/play` (craft-batch-price-confirm).
     */
    public function start(int $characterId, string $recipeKey, int $qty, bool $confirmed = false): array
    {
        $qty  = max(1, $qty);
        $fail = static fn (string $code, string $message, ?array $log = null, array $extra = []): array => $extra + [
            'ok' => false, 'code' => $code, 'message' => $message, 'log' => $log, 'char_task_id' => 0, 'status' => '',
            'started_at' => null, 'ends_at' => null, 'minutes_total' => 0, 'queue_pos' => 0, 'background' => false,
            'breakdown' => null, 'missing_resources' => [], 'missing_items' => [],
            'consumed' => ['gold' => 0, 'resources' => [], 'crafted_items' => []], 'batch' => null,
        ];

        $recipe = $this->recipe($recipeKey);
        if ($recipe === null) {
            return $fail(self::NO_RECIPE, "Неизвестный рецепт: {$recipeKey}");
        }
        $character = $this->character($characterId);
        if ($character === null) {
            return $fail(self::NO_CHARACTER, 'Пользователь или персонаж не найден.');
        }
        $taskRow = $this->taskRow($recipe);
        if ($taskRow === null) {
            return $fail(self::NO_TASK, "Задача '{$this->str($recipe, 'task_name')}' не найдена в базе.");
        }
        $taskId = $this->intField($taskRow, 'id');

        $gate = $this->gateError($recipeKey, $recipe, $character, $taskRow, $qty);
        if ($gate !== null) {
            return $fail($gate['code'], $gate['message'], $gate['log']);
        }

        $resources = $this->intMap($recipe['resources'] ?? []);
        $items     = $this->intMap($recipe['crafted_items'] ?? []);
        $missRes   = $this->checkResources($characterId, $resources, $qty);
        $missItems = $this->checkCraftedItems($characterId, $items, $qty);
        if ($missRes !== [] || $missItems !== []) {
            return $fail(
                self::MISSING_MATERIALS,
                "Недостаточно ресурсов для крафта {$qty} шт.",
                ['reason' => 'missing_materials', 'extra' => ['missing_resources' => $missRes, 'missing_items' => $missItems, 'qty' => $qty]],
                ['missing_resources' => $missRes, 'missing_items' => $missItems],
            );
        }

        $goldRequired = $this->recipeIntField($recipe, 'gold_required') * $qty;
        $breakdown    = (new CraftDurationService($this->gameSettings, $this->buildingEffects()))->forOne($character, $taskRow, $recipe);
        $totalMinutes = $breakdown->minutes * $qty;

        // Подтверждение — после гейтов и сырья (нехватка ведёт на свой экран, а не на «подтверди»)
        // и до транзакции: отказ ничего не трогает. Числа порога — те же, что спишет старт.
        if (! $confirmed && (new CraftBatchConfirmPolicy($this->gameSettings))->needsConfirm($qty, $goldRequired)) {
            $summary = $this->consumedSummary(
                $goldRequired,
                array_map(static fn (int $n): array => ['backpack' => $n * $qty, 'storage' => 0], $resources),
                array_map(static fn (int $n): int => $n * $qty, $items)
            );

            return $fail(self::CONFIRM_REQUIRED, "Крупная партия: подтверди запуск {$qty} шт.", null, [
                'batch' => ['qty' => $qty, 'minutes_total' => $totalMinutes] + $summary,
            ]);
        }

        $startTime    = new DateTime();
        $endTime      = (clone $startTime)->add(new DateInterval('PT' . $totalMinutes . 'M'));

        $db = Database::connect();
        $db->transBegin();

        try {
            // Лимиты очереди/слотов — под блокировкой строки персонажа: два параллельных старта
            // проходят гейт выше каждый по своему снимку, но вставляют строго по очереди.
            $db->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$characterId]);
            $capRefusal = $this->capRefusal($characterId, $taskId, $recipe);
            if ($capRefusal !== null) {
                $db->transRollback();

                return $fail($capRefusal['code'], $capRefusal['message']);
            }
            $sameRecipeCount = $this->sameRecipeCount($characterId, $taskId);
            $isQueued        = $this->characterTaskModel->where([
                'character_id' => $characterId,
                'task_id'      => $taskId,
                'status'       => 'in_work',
            ])->first() !== null;

            $consumedResources = $this->subtractResources($characterId, $resources, $qty);
            $consumedItems     = $this->subtractCraftedItems($characterId, $items, $qty);

            $writer = new ConditionalWriteService($db);
            if ($goldRequired > 0
                && $writer->decrementIfAtLeast('characters', $characterId, 'gold', $goldRequired) !== WriteOutcome::Applied
            ) {
                $db->transRollback();
                $have = $this->characterIntField($this->character($characterId) ?? [], 'gold');

                return $fail(self::NO_GOLD, "Недостаточно золота. Нужно *{$goldRequired}* ед., есть *{$have}* ед.", [
                    'reason' => 'insufficient_gold', 'extra' => ['need' => $goldRequired, 'have' => $have],
                ]);
            }

            $this->characterTaskModel->insert([
                'character_id'     => $characterId,
                'telegram_user_id' => $this->characterIntField($character, 'telegram_user_id'),
                'task_id'          => $taskId,
                // Queued: start_time — placeholder, end_time = NULL (Worker пропускает status != in_work);
                // продвижение очереди выставит start/end и in_work.
                'start_time'       => $startTime->format('Y-m-d H:i:s'),
                'end_time'         => $isQueued ? null : $endTime->format('Y-m-d H:i:s'),
                'status'           => $isQueued ? 'queued' : 'in_work',
                'task_settings'    => json_encode([
                    'recipe'   => $recipeKey,
                    'quantity' => $qty,
                    'consumed' => ['resources' => $consumedResources, 'crafted_items' => $consumedItems, 'gold' => $goldRequired],
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $insertedId = (int) $this->characterTaskModel->getInsertID();

            if ($db->transStatus() === false) {
                $db->transRollback();
                log_message('error', "[CraftOrderService:{$recipeKey}] транзакция упала для character {$characterId}");

                return $fail(self::TX_FAILED, 'Ошибка при создании задачи крафта. Попробуйте ещё раз.');
            }
            $db->transCommit();
        } catch (DatabaseException $e) {
            $db->transRollback();
            log_message('error', "[CraftOrderService:{$recipeKey}] транзакция упала для character {$characterId}: " . $e->getMessage());

            return $fail(self::TX_FAILED, 'Ошибка при создании задачи крафта. Попробуйте ещё раз.');
        } catch (\RuntimeException $e) {
            // ADR-171 race guard: пул или условная запись предметов не списали обещанное —
            // параллельный запрос успел забрать тот же остаток. Откат целиком, задачи нет.
            $db->transRollback();
            log_message('error', "[CraftOrderService:{$recipeKey}] гонка при списании для character {$characterId}: " . $e->getMessage());

            return $fail(self::RACE, self::RACE_TEXT);
        }

        return [
            'ok'                => true,
            'code'              => $isQueued ? self::QUEUED : self::STARTED,
            'message'           => '',
            'log'               => null,
            'char_task_id'      => $insertedId,
            'status'            => $isQueued ? 'queued' : 'in_work',
            'started_at'        => $startTime->format('Y-m-d H:i:s'),
            'ends_at'           => $isQueued ? null : $endTime->format('Y-m-d H:i:s'),
            'minutes_total'     => $totalMinutes,
            'queue_pos'         => $sameRecipeCount + 1,
            'background'        => (new ActionScopeService())->isBackground($taskRow['parallel_execution_allowed'] ?? 1),
            'breakdown'         => $breakdown,
            'missing_resources' => [],
            'missing_items'     => [],
            'consumed'          => $this->consumedSummary($goldRequired, $consumedResources, $consumedItems),
            'batch'             => null,
        ];
    }

    /**
     * Итог списания за всю партию для экрана игрока («Списано: …»): рюкзак и склад сложены,
     * компоненты — русскими именами. Числа — ровно то, что транзакция списала, а не пересчёт рецепта.
     *
     * @param array<string,array{backpack:int,storage:int}> $resources
     * @param array<string,int> $items name_eng → списано
     * @return Consumed
     */
    private function consumedSummary(int $gold, array $resources, array $items): array
    {
        $res = [];
        foreach ($resources as $name => $from) {
            $res[$name] = $from['backpack'] + $from['storage'];
        }
        $named = [];
        foreach ($items as $itemEn => $n) {
            $row  = $this->craftedItemsModel->getRowByName($itemEn);
            $name = is_array($row) && is_string($row['name_rus'] ?? null) && $row['name_rus'] !== '' ? $row['name_rus'] : $itemEn;
            $named[$name] = ($named[$name] ?? 0) + $n;
        }

        return ['gold' => $gold, 'resources' => $res, 'crafted_items' => $named];
    }

    /**
     * Все гейты старта КРОМЕ сырья/крафт-компонентов, в прежнем порядке: эксклюзив ADR-167 →
     * лимит рецепта → слоты → база → здания/уровни → required_crafted_items → квест → фракция →
     * сезон → золото → сила/ловкость/уровень. Зовут старт, превью и докупка недостающего
     * (`CraftShortfallBuyAction` — со снимком золота ПОСЛЕ покупки).
     *
     * @param array<string,mixed> $recipe
     * @param array<array-key,mixed>|CharacterEntity $character
     * @param array<array-key,mixed> $taskRow
     * @return Refusal|null
     */
    public function gateError(string $recipeKey, array $recipe, array|CharacterEntity $character, array $taskRow, int $quantity): ?array
    {
        // Рецепт, выключенный флагом фичи, не стартует и прямым callback'ом — первым после «нет рецепта».
        if (!$this->recipeEnabled($recipeKey)) {
            return $this->refusal(self::FEATURE_OFF, 'Этот рецепт сейчас недоступен.', 'recipe_feature_disabled');
        }

        $charId = $this->characterIntField($character, 'id');
        $taskId = $this->intField($taskRow, 'id');

        // ADR-167: 🔒-крафт не стартует поверх другого 🔒-дела; свой же рецепт помехой не
        // считается — он уйдёт в очередь.
        $taskNameRus = is_string($taskRow['name_rus'] ?? null) ? $taskRow['name_rus'] : '';
        $conflict    = (new ActiveTasksService())->exclusiveConflict(
            $charId,
            $taskRow['parallel_execution_allowed'] ?? 1,
            $taskNameRus,
            $taskId,
        );
        if ($conflict !== null) {
            return $this->refusal(self::BUSY, $conflict, 'exclusive_task_busy');
        }

        $cap = $this->capRefusal($charId, $taskId, $recipe);
        if ($cap !== null) {
            return $cap;
        }

        if (!empty($recipe['requires_base'])) {
            $hasBase = (new ClaimedCellModel())->where('character_id', $charId)->first();
            if (!$hasBase) {
                return $this->refusal(self::NO_BASE, 'У вас нет построенной базы (лагеря).', 'no_base');
            }
        }

        // S16 (ADR-026): постройки + level-aware check через recipe.required_building_levels.
        $requiredBuildingLevels = (isset($recipe['required_building_levels']) && is_array($recipe['required_building_levels']))
            ? $recipe['required_building_levels']
            : [];
        $requiredBuildings = isset($recipe['required_buildings']) && is_array($recipe['required_buildings']) ? $recipe['required_buildings'] : [];
        foreach ($requiredBuildings as $buildingNameEn) {
            $nameEn   = is_string($buildingNameEn) ? $buildingNameEn : '';
            $building = $this->buildingModel->where('name_en', $nameEn)->first();
            if (!is_array($building)) {
                log_message('error', "[CraftOrderService:{$recipeKey}] здание '{$nameEn}' не найдено в БД");

                return $this->refusal(self::CONFIG_ERROR, "Конфигурационная ошибка: здание '{$nameEn}' не найдено в БД.");
            }
            $hasBuilding = $this->characterBuildingModel
                ->where('character_id', $charId)
                ->where('building_id', $building['id'])
                ->first();
            if (!$hasBuilding) {
                $rusName = BuildingModel::rusName($building, $nameEn);

                return $this->refusal(
                    self::NO_BUILDING,
                    "У вас нет необходимого здания: *{$rusName}*. Постройте его, чтобы крафтить.",
                    'missing_building',
                    ['building' => $nameEn],
                );
            }
            $needLevelRaw = $nameEn !== '' && isset($requiredBuildingLevels[$nameEn]) ? $requiredBuildingLevels[$nameEn] : 0;
            $needLevel    = is_numeric($needLevelRaw) ? (int) $needLevelRaw : 0;
            if ($needLevel > 0) {
                $haveLevelRaw = is_array($hasBuilding) && isset($hasBuilding['level']) ? $hasBuilding['level'] : 0;
                $haveLevel    = is_numeric($haveLevelRaw) ? (int) $haveLevelRaw : 0;
                if ($haveLevel < $needLevel) {
                    $rusNameForLevel = BuildingModel::rusName($building, $nameEn);

                    return $this->refusal(
                        self::BUILDING_LEVEL,
                        "Здание *{$rusNameForLevel}* должно быть уровня *{$needLevel}* (сейчас *{$haveLevel}*). Прокачай и возвращайся.",
                        'insufficient_building_level',
                        ['building' => $nameEn, 'need' => $needLevel, 'have' => $haveLevel],
                    );
                }
            }
        }

        // S17 (ADR-026): non-consumable crafted_items — gate без списания.
        $requiredCraftedItemsRaw = $recipe['required_crafted_items'] ?? [];
        $missingRequiredItems    = $this->checkRequiredCraftedItems(
            $charId,
            is_array($requiredCraftedItemsRaw) ? $requiredCraftedItemsRaw : [],
        );
        if ($missingRequiredItems !== []) {
            $firstMissing = reset($missingRequiredItems);

            return $this->refusal(
                self::NO_REQUIRED_ITEM,
                "Нужно иметь *{$firstMissing['name']}* в инвентаре. Скрафти его и возвращайся.",
                'missing_required_crafted_item',
                ['missing' => $missingRequiredItems],
            );
        }

        // S25 (ADR-029): quest-gate.
        $requiredQuest = isset($recipe['required_quest']) && is_string($recipe['required_quest']) ? $recipe['required_quest'] : '';
        if ($requiredQuest !== '' && !$this->isQuestCompleted($charId, $requiredQuest)) {
            return $this->refusal(
                self::QUEST,
                'Этот рецепт откроется после захвата стратегического объекта (квест ещё не завершён).',
                'required_quest_incomplete',
                ['quest' => $requiredQuest],
            );
        }

        // S25 (ADR-029): faction-gate.
        $requiredFaction = isset($recipe['required_faction']) && is_numeric($recipe['required_faction']) ? (int) $recipe['required_faction'] : 0;
        if ($requiredFaction > 0 && $this->characterFactionId($charId) !== $requiredFaction) {
            return $this->refusal(
                self::FACTION,
                'Это фракционное оружие может скрафтить только член соответствующей фракции.',
                'required_faction_mismatch',
                ['need' => $requiredFaction],
            );
        }

        // S28 (ADR-032): seasonal-gate (меню показывает только активные, гейт страхует прямой callback).
        $requiredSeason = isset($recipe['required_season']) && is_string($recipe['required_season']) ? $recipe['required_season'] : '';
        if ($requiredSeason !== '') {
            $seasonalService = new \App\Services\World\SeasonalCraftService();
            if (!$seasonalService->isSeasonActive($requiredSeason)) {
                $label    = $seasonalService->getSeasonLabel($requiredSeason);
                $labelTxt = $label !== '' ? "«{$label}»" : 'свой сезон';

                return $this->refusal(
                    self::SEASON,
                    "Этот сезонный рецепт сейчас недоступен — вернётся в сезон {$labelTxt}.",
                    'season_inactive',
                    ['required_season' => $requiredSeason],
                );
            }
        }

        // F3.B8: золото (умножается на quantity).
        $goldRequired = $this->recipeIntField($recipe, 'gold_required') * $quantity;
        $goldHave     = $this->characterIntField($character, 'gold');
        if ($goldRequired > 0 && $goldHave < $goldRequired) {
            return $this->refusal(
                self::NO_GOLD,
                "Недостаточно золота. Нужно *{$goldRequired}* ед., есть *{$goldHave}* ед.",
                'insufficient_gold',
                ['need' => $goldRequired, 'have' => $goldHave],
            );
        }

        // F3.B9 + S16: stat-требования; уровень — через RecipeGateResolver (GameSettings).
        $levelRequired = (new RecipeGateResolver(fn (string $k, $d) => $this->gameSettings->get($k, $d)))->requiredLevel($recipe);
        $statChecks    = [
            'strength' => $this->recipeIntField($recipe, 'required_strength'),
            'agility'  => $this->recipeIntField($recipe, 'required_agility'),
            'level'    => $levelRequired,
        ];
        foreach ($statChecks as $stat => $needed) {
            if ($needed <= 0) {
                continue;
            }
            $have = $this->characterIntField($character, $stat);
            if ($have < $needed) {
                $statRus = ['strength' => 'силы', 'agility' => 'ловкости', 'level' => 'уровня'][$stat];

                return $this->refusal(
                    self::NO_STAT,
                    "Недостаточно {$statRus}. Нужно *{$needed}*, есть *{$have}*.",
                    "insufficient_{$stat}",
                    ['need' => $needed, 'have' => $have],
                );
            }
        }

        return null;
    }

    /**
     * ADR-171: достаточность по пулу рюкзак+склад; `storage`/`pooled` нужны экрану нехватки.
     *
     * @param array<string,int> $reqs name_rus → количество на 1 шт.
     * @return array<string,array{need:int,have:int,name:string,storage:int,pooled:bool}>
     */
    public function checkResources(int $charId, array $reqs, int $qty): array
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

    /**
     * @param array<string,int> $reqs name_eng → количество на 1 шт.
     * @return array<string,array{need:int,have:int,name:string}>
     */
    public function checkCraftedItems(int $charId, array $reqs, int $qty): array
    {
        $missing = [];
        foreach ($reqs as $itemEn => $perOne) {
            $need = $perOne * $qty;
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            if (!$item) {
                $missing[$itemEn] = ['need' => $need, 'have' => 0, 'name' => $itemEn . ' (не найден)'];
                continue;
            }
            [$have, $name] = $this->craftedItemHave($charId, $itemEn);
            if ($have < $need) {
                $missing[$itemEn] = ['need' => $need, 'have' => $have, 'name' => $name];
            }
        }

        return $missing;
    }

    /**
     * ADR-171: списание через пул — рюкзак сначала, остаток со склада, внутри транзакции
     * вызывающего. `RuntimeException` (гонка) намеренно не ловится: вызывающий откатывает всё.
     *
     * @param array<string,int> $reqs
     * @return array<string,array{backpack:int,storage:int}> откуда реально списано — для возврата при отмене
     * @throws \RuntimeException при гонке за тот же остаток
     */
    public function subtractResources(int $charId, array $reqs, int $qty): array
    {
        $taken = [];
        foreach ($reqs as $resName => $perOne) {
            $need = $perOne * $qty;
            if ($need < 1) {
                continue;
            }
            $taken[$resName] = $this->resourcePool->consumeByName($charId, $resName, $need);
        }

        return $taken;
    }

    /** S25 (ADR-029): faction_id персонажа (0 если нет записи / Нейтрал). */
    public function characterFactionId(int $charId): int
    {
        $query = Database::connect()->table('character_factions')->where('character_id', $charId)->get();
        $row   = $query !== false ? $query->getFirstRow('array') : null;

        return is_array($row) && isset($row['faction_id']) && is_numeric($row['faction_id'])
            ? (int) $row['faction_id']
            : 0;
    }

    /**
     * Предметы-ингредиенты — условной записью (ADR-181): нет строки или остаток меньше нужного →
     * `RuntimeException`, вызывающий откатывает транзакцию.
     *
     * @param array<string,int> $reqs
     * @return array<string,int> name_eng → списано
     * @throws \RuntimeException
     */
    private function subtractCraftedItems(int $charId, array $reqs, int $qty): array
    {
        $taken  = [];
        $writer = new ConditionalWriteService(Database::connect());
        foreach ($reqs as $itemEn => $perOne) {
            $need = $perOne * $qty;
            if ($need < 1) {
                continue;
            }
            $item = $this->craftedItemsModel->getRowByName($itemEn);
            $log  = is_array($item) ? $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($this->intField($item, 'id'), $charId) : null;
            if (!is_array($log)
                || $writer->decrementIfAtLeast('crafted_items_log', $this->intField($log, 'id'), 'quantity', $need, true) !== WriteOutcome::Applied
            ) {
                throw new \RuntimeException("предмет {$itemEn}: не хватило {$need} шт. при списании");
            }
            $taken[$itemEn] = $need;
        }

        return $taken;
    }

    /**
     * Лимиты очереди рецепта и слотов крафта (GameSettings `craft.queue.*`).
     *
     * @param array<string,mixed> $recipe
     * @return Refusal|null
     */
    private function capRefusal(int $charId, int $taskId, array $recipe): ?array
    {
        $maxPerRecipe    = $this->settingInt(self::KEY_MAX_PER_RECIPE, self::DEFAULT_MAX_PER_RECIPE);
        $sameRecipeCount = $this->sameRecipeCount($charId, $taskId);
        if ($sameRecipeCount >= $maxPerRecipe) {
            return $this->refusal(
                self::QUEUE_FULL,
                "Очередь крафта *{$this->str($recipe, 'item_name_rus')}* заполнена ("
                    . "{$maxPerRecipe} макс.). Дождись завершения или отмени один.",
            );
        }

        // Слот = distinct task_id крафт-задач in_work/queued; новый рецепт при занятых слотах — отказ.
        if ($sameRecipeCount === 0) {
            $maxSlots = $this->settingInt(self::KEY_MAX_SLOTS, self::DEFAULT_MAX_SLOTS);
            if ($this->countDistinctActiveSlots($charId) >= $maxSlots) {
                return $this->refusal(
                    self::SLOTS_FULL,
                    "Все *{$maxSlots}* слота крафта заняты. Дождись завершения одного из активных или отмени запас.",
                );
            }
        }

        return null;
    }

    private function sameRecipeCount(int $charId, int $taskId): int
    {
        $n = $this->characterTaskModel
            ->where('character_id', $charId)
            ->where('task_id', $taskId)
            ->whereIn('status', ['in_work', 'queued'])
            ->countAllResults();

        return (int) $n;
    }

    /** v0.51.265: слот крафта — только tasks.type='craft' (робот-добытчик и стройка слот не занимают). */
    private function countDistinctActiveSlots(int $characterId): int
    {
        $query = Database::connect()->query(
            'SELECT COUNT(DISTINCT ct.task_id) AS n FROM character_tasks ct'
            . ' INNER JOIN tasks t ON t.id = ct.task_id'
            . " WHERE ct.character_id = ? AND ct.status IN ('in_work', 'queued') AND t.type = 'craft'",
            [$characterId]
        );
        $row = $query instanceof \CodeIgniter\Database\BaseResult ? $query->getRowArray() : null;

        return is_array($row) ? $this->intField($row, 'n') : 0;
    }

    /**
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
            [$have, $name] = $this->craftedItemHave($charId, $itemEn);
            if ($have < $needInt) {
                $missing[$itemEn] = ['need' => $needInt, 'have' => $have, 'name' => $name];
            }
        }

        return $missing;
    }

    /** @return array{0:int, 1:string} сколько штук у персонажа и русское имя предмета */
    private function craftedItemHave(int $charId, string $itemEn): array
    {
        $item = $this->craftedItemsModel->getRowByName($itemEn);
        if (!is_array($item)) {
            return [0, $itemEn . ' (не найден)'];
        }
        $log  = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($this->intField($item, 'id'), $charId);
        $have = is_array($log) ? $this->intField($log, 'quantity') : 0;
        $name = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : $itemEn;

        return [$have, $name];
    }

    /** S25 (ADR-029): завершён ли quest по `quests.title_en`. */
    private function isQuestCompleted(int $charId, string $titleEn): bool
    {
        $query = Database::connect()->table('quest_steps qs')
            ->join('quests q', 'q.id = qs.quest_id')
            ->where('q.title_en', $titleEn)
            ->where('qs.character_id', $charId)
            ->where('qs.is_completed', 1)
            ->get();

        return $query !== false && $query->getFirstRow('array') !== null;
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

    /** @return array<string,mixed>|null */
    private function recipe(string $recipeKey): ?array
    {
        if ($recipeKey === '') {
            return null;
        }
        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');

        return $cfg->get($recipeKey);
    }

    private function character(int $characterId): ?CharacterEntity
    {
        $row = (new CharacterModel())->find($characterId);

        return $row instanceof CharacterEntity ? $row : null;
    }

    /**
     * @param array<string,mixed> $recipe
     * @return array<array-key,mixed>|null
     */
    private function taskRow(array $recipe): ?array
    {
        $row = (new TaskModel())->where('name', $this->str($recipe, 'task_name'))->first();

        return is_array($row) ? $row : null;
    }

    private function buildingEffects(): BuildingEffectsService
    {
        return new BuildingEffectsService($this->characterBuildingModel, $this->buildingModel);
    }

    /**
     * Рецепт не выключен флагом фичи: рыбные блюда — `cooking.fish_dishes.enabled`, дроны — флаги
     * {@see DroneService}. Единственный список; веб-каталог фильтрует им же.
     */
    public function recipeEnabled(string $recipeKey): bool
    {
        if (in_array($recipeKey, self::FISH_RECIPES, true)) {
            $raw = $this->gameSettings->get(self::FISH_FLAG, false);

            return is_bool($raw) ? $raw : (is_numeric($raw) && (int) $raw === 1);
        }

        return match ($recipeKey) {
            'DroneScout'  => (new DroneService($this->gameSettings))->isEnabled(),
            'DroneCargo'  => (new DroneService($this->gameSettings))->cargoIsEnabled(),
            'DroneRepair' => (new DroneService($this->gameSettings))->repairIsEnabled(),
            'DroneCombat' => (new DroneService($this->gameSettings))->combatIsEnabled(),
            default       => true,
        };
    }

    /**
     * @param array<string,mixed> $extra
     * @return Refusal
     */
    private function refusal(string $code, string $message, ?string $logReason = null, array $extra = []): array
    {
        return [
            'code'    => $code,
            'message' => $message,
            'log'     => $logReason === null ? null : ['reason' => $logReason, 'extra' => $extra],
        ];
    }

    private function settingInt(string $key, int $default): int
    {
        $raw = $this->gameSettings->get($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    /** @return array<string,int> */
    private function intMap(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = (int) $v;
            }
        }

        return $out;
    }

    /** @param array<array-key,mixed>|CharacterEntity $character */
    private function characterIntField(array|CharacterEntity $character, string $key): int
    {
        $raw = $character[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** @param array<array-key,mixed> $row */
    private function intField(array $row, string $key): int
    {
        $raw = $row[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** @param array<string,mixed> $recipe */
    private function recipeIntField(array $recipe, string $key): int
    {
        return $this->intField($recipe, $key);
    }

    /** @param array<string,mixed> $recipe */
    private function str(array $recipe, string $key): string
    {
        return is_string($recipe[$key] ?? null) ? $recipe[$key] : '';
    }
}
