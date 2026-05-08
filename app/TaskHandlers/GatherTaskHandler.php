<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Models\TaskModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use DateTime;
use App\Libraries\BiomeResourceModifier;
use App\Libraries\ToolManager;
use App\Services\Player\Gather\GatherCellResourceQuery;
use App\Services\Player\Gather\GatherEventModifierService;
use App\Services\Player\Gather\GatherFormulaService;
use App\Services\Player\Gather\GatherMessageFormatter;
use App\Services\Player\Gather\GatherResultPersister;
use App\Services\Player\Gather\ToolDurabilityProcessor;

/**
 * v0.51.23 (F2.9 batch-5 — FINAL closure): extends BaseTaskHandler.
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init, Request::sendPhoto → safeSendPhoto.
 * `handle($task)` → `handle(array $task = []): void`.
 *
 * F2.9 ПОВНІСТЮ ЗАКРИТО v0.51.23 — всі 12 task-handlers extends BaseTaskHandler.
 *
 * v0.51.33 perf: 2 N+1 елімінації у найгарячіших шляхах:
 *  - calculateFoundResources: reuse $baseBlockResources['resource'] замість resourceModel->find.
 *  - saveFoundResources: 1 whereIn(id_resources) замість N SELECT existing rows у loop'і.
 *
 * v0.51.35 perf: reply path optimized через property-access pattern:
 *  - sendResourcesFoundReply: ResourceModel::findAllCached() (typed list<ResourceEntity>,
 *    1h TTL) preload + group-by-rarity inline (krsort) уникає intermediate
 *    array{name,amount,rarity} який PHPStan @ level 9 не narrow без @var.
 *    Property access $entity->name/$entity->rarity дає typed string/int.
 *  - Tools: 1 whereIn(name_eng) batch + is_string guards для craftedItemsModel
 *    (returnType='array' дає mixed по замовчуванню).
 *
 * Cumulative: ~17 SQL/gather → ~3 SQL (-82%) у full gather completion path
 * (calc+save+reply).
 */
class GatherTaskHandler extends BaseTaskHandler
{
    private GatherFormulaService $formulaService;
    private ToolDurabilityProcessor $toolDurability;
    private GatherEventModifierService $eventService;
    private GatherMessageFormatter $messageFormatter;
    private GatherResultPersister $resultPersister;
    private GatherCellResourceQuery $cellQuery;

    protected $characterModel;
    protected $characterTaskModel;
    protected $resourceModel;
    protected $telegramUserModel;
    protected $craftedItemsModel;
    protected $biomeResourceModifier;

    /**
     * Сохраняет, сколько инструментов мы использовали в рамках одного процесса сбора
     * (ключ: имя инструмента, значение: сколько раз применили).
     */
    protected array $usedToolsCount = [];

    public function __construct()
    {
        // v0.51.107 (decomp Step 4): drop unused/redundant properties (mapModel,
        // biomeModel, eventModel, activeEventModel, craftedItemsLogModel,
        // characterResourceModel, toolManager, taskModel) — їх використовували
        // тільки sub-services ctors (тепер inline). taskModel НЕ used at all.
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->resourceModel          = new ResourceModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->biomeResourceModifier  = new BiomeResourceModifier();

        // F2.7 first slice: чистые формулы.
        $this->formulaService         = new GatherFormulaService();
        // F2.7b: N+1 fix tools — own dependency tree.
        $this->toolDurability         = new ToolDurabilityProcessor(
            new CraftedItemsLogModel(),
            $this->craftedItemsModel,
            new ToolManager()
        );
        // F2.7c: батч событий — own dependency tree.
        $this->eventService           = new GatherEventModifierService(
            new EventModel(),
            new ActiveEventModel()
        );
        // v0.51.104 Step 1: pure HTML reply formatter.
        $this->messageFormatter       = new GatherMessageFormatter();
        // v0.51.105 Step 2: DB persistence (resources + stat gains).
        $this->resultPersister        = new GatherResultPersister($this->characterModel);
        // v0.51.106 Step 3: cell+biome+resources lookup chain.
        $this->cellQuery              = new GatherCellResourceQuery(
            null,
            null,
            $this->resourceModel
        );
    }

    /**
     * Основная точка входа для обработки задачи сбора ресурсов.
     *
     * @param array<string,mixed> $task — запис з character_tasks.
     */
    public function handle(array $task = []): void
    {
        $character = $this->characterModel->find($task['character_id']);
        if (!$character) {
            return;
        }

        // F2.7c: один batch на все 5 relevant событий вместо ~12 индивидуальных SQL.
        $loadedEvents = $this->eventService->loadActiveEvents();

        // v0.51.106 (decomp Step 3): single round-trip cell+biome+resources lookup
        // (раніше — duplicate calls у getAvailableResources + handle line 137-139).
        $context     = $this->cellQuery->loadCellContext(
            (int) $character['cell_number'],
            (int) $character['level']
        );
        $resources   = $context['resources'];
        $biome       = $context['biome'];
        $biomeName   = $biome['name'] ?? '???';

        $spentMinutes = $this->calculateSpentMinutes($task['start_time'], $task['end_time']);

        $foundResources = $this->calculateFoundResources(
            resources: $resources,
            spentMinutes: $spentMinutes,
            character: $character,
            task: $task,
            loadedEvents: $loadedEvents
        );

        // Применяем возможные модификаторы биома
        $foundResources = $this->biomeResourceModifier->modifyResourcesByBiome($biome['id'] ?? null, $foundResources);

        // Сохраняем результаты
        $this->saveFoundResources($foundResources, $character, $task);
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Отправляем уведомление
        $this->sendResourcesFoundReply($foundResources, $character, $spentMinutes, $biomeName);
    }

    /**
     * Основной метод расчёта добытых ресурсов
     * (блочная логика по 10 минут + бонус от инструментов).
     */
    protected function calculateFoundResources(
        array $resources,
        int $spentMinutes,
        array|\App\Entities\CharacterEntity $character,
        array $task,
        array $loadedEvents
    ): array {
        $allowedRarities = $this->getAllowedRarities($character['level']);

        $blocksCount = intdiv($spentMinutes, 10);
        $remainder   = $spentMinutes % 10;

        $baseBlockResources = [];
        foreach ($resources as $resource) {
            if (!in_array($resource['rarity'], $allowedRarities)) {
                continue;
            }
            $baseFor10Min = $this->getBaseQuantityByRarity($resource['rarity']);

            $baseBlockResources[$resource['id']] = [
                'resource' => $resource,
                'baseQty'  => $baseFor10Min,
            ];
        }

        // F2.7b: один batch — все инструменты персонажа, релевантные хотя бы
        // одному ресурсу. Заменяет ~920 SQL на ~2 + рефреши после расхода.
        $resourceNames = [];
        foreach ($baseBlockResources as $arr) {
            $resourceNames[] = $arr['resource']['name'];
        }
        $availableTools = $this->toolDurability->loadAvailableTools(
            (int) $character['id'],
            $resourceNames
        );

        $foundAmounts = []; // resourceId => суммарное кол-во

        // Цикл по блокам
        for ($blockIndex = 0; $blockIndex < $blocksCount; $blockIndex++) {
            $toolsNeeded = [];
            $resourceBonuses = [];

            // Определяем лучший инструмент per resource из cached map.
            foreach ($baseBlockResources as $resId => $arr) {
                $resourceName = $arr['resource']['name'];
                $best = $this->toolDurability->pickBestTool($resourceName, $availableTools);

                if ($best === null) {
                    $resourceBonuses[$resId] = 0.0;
                    continue;
                }

                $resourceBonuses[$resId]      = $best['bonus'];
                $toolsNeeded[$best['name']][] = $resId;
            }

            // Списываем прочность; cache самообновляется.
            foreach ($toolsNeeded as $toolName => $listOfResources) {
                $ok = $this->toolDurability->consumeAndRefresh(
                    $toolName,
                    $availableTools,
                    (int) $character['id']
                );
                if (!$ok) {
                    foreach ($listOfResources as $resId) {
                        $resourceBonuses[$resId] = 0.0;
                    }
                    continue;
                }

                if (!isset($this->usedToolsCount[$toolName])) {
                    $this->usedToolsCount[$toolName] = 0;
                }
                $this->usedToolsCount[$toolName]++;
            }

            // Подсчёт добычи
            foreach ($baseBlockResources as $resId => $arr) {
                $baseQty  = $arr['baseQty'];
                $bonusVal = $resourceBonuses[$resId] ?? 0.0;

                $resultQty = $baseQty;
                if ($bonusVal > 0) {
                    $resultQty *= (1 + $bonusVal);
                }

                if ($resultQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $resultQty;
                }
            }
        }

        // Остаток (меньше 10 мин)
        if ($remainder > 0) {
            $leftFactor = $remainder / 10.0;
            foreach ($baseBlockResources as $resId => $arr) {
                $addQty = $arr['baseQty'] * $leftFactor;
                if ($addQty > 0) {
                    $foundAmounts[$resId] = ($foundAmounts[$resId] ?? 0) + $addQty;
                }
            }
        }

        // Применяем ±20% и прочие модификаторы (здоровье, события)
        $foundResources = [];
        foreach ($foundAmounts as $resId => $amountRaw) {
            $randFactor = rand(80, 120) / 100.0;
            $amt = $amountRaw * $randFactor;

            // v0.51.33 perf: reuse already-loaded $baseBlockResources['resource']
            // (was N+1 resourceModel->find).
            $resInfo = $baseBlockResources[$resId]['resource'] ?? null;
            if (!$resInfo) {
                continue;
            }

            // F2.7c: per-resource модификаторы (FishStock/ExoticFlowering/
            // BerryBoom/LocustExodus) консолидированы в eventService.
            $amt = $this->eventService->applyResourceModifiers(
                $amt,
                (string) ($resInfo['name'] ?? ''),
                $loadedEvents
            );

            // Учет здоровья/усталости
            $htFactor = $this->getHealthTirednessFactor($character);
            $amt *= $htFactor;

            $finalAmt = (int) round($amt);
            if ($finalAmt < 1) {
                continue;
            }

            $foundResources[] = [
                'resource_id' => $resId,
                'amount'      => $finalAmt,
                'type'        => $resInfo['type'] ?? '',
            ];
        }

        // F2.7c: проверка засухи делегирована eventService.
        $foundResources = $this->eventService->applyDrynessPenalty($foundResources, $loadedEvents);

        return $foundResources;
    }

    // F2.7 first slice: getAllowedRarities / getBaseQuantityByRarity /
    // getHealthTirednessFactor вынесены в App\Services\Player\Gather\GatherFormulaService.
    // Делегирующие методы оставлены для backward-compat внутреннего вызова
    // calculateFoundResources в этом классе.
    protected function getAllowedRarities(int $level): array
    {
        return $this->formulaService->getAllowedRarities($level);
    }

    protected function getBaseQuantityByRarity(int $rarity): int
    {
        return $this->formulaService->getBaseQuantityByRarity($rarity);
    }

    protected function getHealthTirednessFactor(array|\App\Entities\CharacterEntity $character): float
    {
        return $this->formulaService->getHealthTirednessFactor($character);
    }

    /**
     * v0.51.106 (decomp Step 3): delegated to GatherCellResourceQuery.
     * Backward-compat wrapper зберіг для legacy callers (none у поточному коді).
     */
    protected function getAvailableResources(array|\App\Entities\CharacterEntity $character): array
    {
        $context = $this->cellQuery->loadCellContext(
            (int) $character['cell_number'],
            (int) $character['level']
        );
        return $context['resources'];
    }

    protected function calculateSpentMinutes(string $startTime, string $endTime): int
    {
        // F2.7 first slice: вынесено в GatherFormulaService.
        return $this->formulaService->calculateSpentMinutes($startTime, $endTime);
    }

    /**
     * v0.51.105 (decomp Step 2): DB persistence delegated to GatherResultPersister.
     */
    protected function saveFoundResources(array $foundResources, array|\App\Entities\CharacterEntity $character, array $task): void
    {
        $this->resultPersister->persist($foundResources, $character, $task);
    }

    /**
     * Отправляет сообщение (без картинки) об итогах сбора.
     *
     * v0.51.35 perf: pre-load ResourceEntity map once через findAllCached() (1h TTL,
     * after warm = 0 SQL). Property-access ($entity->name typed string, $entity->rarity
     * typed int) дає PHPStan-clean reply path.
     */
    protected function sendResourcesFoundReply(
        array $foundResources,
        array|\App\Entities\CharacterEntity $character,
        int $spentMinutes,
        string $biomeName
    ): void {
        $userRow = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            log_message('error', "sendResourcesFoundReply: No telegram_id for character_id={$character['id']}");
            return;
        }
        $chatId = $userRow['telegram_id'];

        // v0.51.104: pre-load ResourceEntity map (1× cached, 1h TTL).
        $resourceMap = [];
        foreach ($this->resourceModel->findAllCached() as $entity) {
            $resourceMap[$entity->id] = $entity;
        }

        // v0.51.104: pre-load tool map якщо є tools used.
        $toolByName = [];
        if (!empty($this->usedToolsCount)) {
            $toolNames = array_keys($this->usedToolsCount);
            foreach ($this->craftedItemsModel->whereIn('name_eng', $toolNames)->findAll() as $row) {
                if (is_array($row) && isset($row['name_eng']) && is_string($row['name_eng'])) {
                    $toolByName[$row['name_eng']] = $row;
                }
            }
        }

        $reply = $this->messageFormatter->buildResourcesFoundReply(
            $foundResources,
            $biomeName,
            $spentMinutes,
            $resourceMap,
            $this->usedToolsCount,
            $toolByName
        );

        $this->safeSendPhoto(
            $chatId,
            base_url('uploads/telegram/loot_resources_in_the_box.png'),
            $reply['text'],
            ['parse_mode' => 'HTML', 'reply_markup' => $reply['keyboard']]
        );
    }

}
