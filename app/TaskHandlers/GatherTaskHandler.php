<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\EventModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Libraries\ToolManager;
use App\Services\Player\Gather\BiomeGatherProfileService;
use App\Services\Player\Gather\GatherCellResourceQuery;
use App\Services\Player\Gather\GatherEventModifierService;
use App\Services\Player\Gather\GatherFormulaService;
use App\Services\Player\Gather\GatherMessageFormatter;
use App\Services\Player\Gather\GatherResultPersister;
use App\Services\Player\Gather\ToolDurabilityProcessor;
use App\Services\Farming\FarmingService;
use App\Services\Food\FoodBuffService;

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
#[HandlerKey(
    key: 'gather',
    displayName: 'Сбор ресурсов',
    description: 'Завершение задачи добычи ресурсов (Gather). Считает найденное по биому+инструменту+событиям, износ инструментов, сохраняет в банк.',
)]
class GatherTaskHandler extends BaseTaskHandler
{
    /** W28 (ADR-083) — рутинное завершение задачи: при активном killswitch уведомление шлётся тихо (disable_notification). */
    protected function isRoutineNotification(): bool
    {
        return true;
    }

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
    // V22 (ADR-054): biome-driven профиль добычи (заменил Libraries\BiomeResourceModifier).
    private BiomeGatherProfileService $biomeGatherProfile;

    // V6 (ADR-033): вторичный источник семян — light drop при сборе ресурсов.
    private FarmingService $farming;
    // V9 (ADR-034): «Сытость» — множитель добычи, пока сыт.
    private FoodBuffService $foodBuff;
    // ADR-090 «Мягкий старт»: soft-ramp rarity-доступа на низких уровнях (live-tunable, killswitch).
    private \App\Services\GameSettings\GameSettingsService $gameSettings;

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
        // V22 (ADR-054): GameSettings-driven biome-профиль (9 биомов, live-tunable).
        $this->biomeGatherProfile     = new BiomeGatherProfileService();

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
        // (стат-гейны с 2026-07-13 идут через CharacterStatsService — CharacterModel не нужен)
        $this->resultPersister        = new GatherResultPersister();
        // v0.51.106 Step 3: cell+biome+resources lookup chain.
        $this->cellQuery              = new GatherCellResourceQuery(
            null,
            null,
            $this->resourceModel
        );
        // V6 (ADR-033): seed-drop reader (GameSettings killswitch + chance).
        $this->farming                = new FarmingService();
        // V9 (ADR-034): food-buff reader (well-fed gather multiplier).
        $this->foodBuff               = new FoodBuffService();
        // ADR-090 «Мягкий старт»: reader soft-ramp параметров (killswitch+window+step).
        $this->gameSettings           = new \App\Services\GameSettings\GameSettingsService();
    }

    /**
     * ADR-090 — параметры «мягкого старта» добычи из GameSettings (live-tunable).
     *
     * @return array{enabled:bool, window:int, step:float}
     */
    private function earlyAccessParams(): array
    {
        $enRaw = $this->gameSettings->get('gather.early_access_enabled', false);
        $enabled = is_bool($enRaw)
            ? $enRaw
            : (is_numeric($enRaw) ? ((int) $enRaw === 1) : in_array(strtolower((string) $enRaw), ['1', 'true', 'yes', 'on'], true));

        $winRaw  = $this->gameSettings->get('gather.early_access_window', 2);
        $stepRaw = $this->gameSettings->get('gather.early_access_step', 0.20);

        return [
            'enabled' => $enabled,
            'window'  => is_numeric($winRaw) ? (int) $winRaw : 2,
            'step'    => is_numeric($stepRaw) ? (float) $stepRaw : 0.20,
        ];
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

        // S4: сброс per-session состояния processor'а (защита от повторного
        // использования инстанса между разными gather задачами в worker'е).
        $this->toolDurability->clearBrokenTools();
        $this->usedToolsCount = [];

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

        // V22 (ADR-054): biome-driven профиль добычи — signature-ресурсы ×буст,
        // scarce-ресурсы ×дефицит (live-tunable, killswitch). Имена резолвим из уже
        // загруженных $resources (без доп. SQL — не N+1). Детерминированно (0 RNG).
        $biomeId  = isset($biome['id']) && is_numeric($biome['id']) ? (int) $biome['id'] : null;
        $nameById = [];
        foreach ($resources as $r) {
            $ridRaw  = $r['id'] ?? null;
            $nameRaw = $r['name'] ?? null;
            if (is_numeric($ridRaw) && is_scalar($nameRaw)) {
                $nameById[(int) $ridRaw] = (string) $nameRaw;
            }
        }
        $foundResources = $this->biomeGatherProfile->modifyResourcesByBiome($biomeId, $foundResources, $nameById);

        // V9 (ADR-034): «Сытость» делает добычу щедрее (детерминир., pure-bonus;
        // не сыт / выключено → no-op). Изолировано: только масштаб итоговой добычи.
        $foundResources = $this->applyWellFedGatherBonus($foundResources, $character);

        // ADR-085 Склад Фаза 1b: weight-cap clamp (dormant до killswitch ON).
        // Overflow-политика «добор до cap + излишек не собран». OFF → byte-identical.
        $foundResources = $this->applyWeightCapClamp($foundResources, $character);

        // Сохраняем результаты
        $this->saveFoundResources($foundResources, $character, $task);
        $this->characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Отправляем уведомление (V22: + signature-хинт «биом богат на …»).
        $signatureNames = $this->biomeGatherProfile->signatureNamesFor($biomeId);
        $this->sendResourcesFoundReply($foundResources, $character, $spentMinutes, $biomeName, $signatureNames);

        // ADR-085 Склад Фаза 1b: уведомить, если рюкзак переполнился (clamp+notify). Dormant → no-op.
        $this->notifyWeightCapOverflow($character);

        // V6 (ADR-033): вторичный источник семян (defensive, killswitch+chance).
        // Изолировано от gather-математики — не влияет на расчёт/сохранение добычи.
        $this->maybeDropSeed($character);
    }

    /**
     * V9 (ADR-034) — «Сытость» делает добычу щедрее (×food.well_fed.gather_yield_multiplier),
     * пока now < character.well_fed_until. Детерминир., pure-bonus: не сыт / выключено →
     * множитель ≤1.0 → возврат без изменений. Масштабирует только итоговые amount'ы.
     *
     * @param list<array<string,mixed>> $foundResources
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @return list<array<string,mixed>>
     */
    private function applyWellFedGatherBonus(array $foundResources, array|\App\Entities\CharacterEntity $character): array
    {
        $mult = $this->foodBuff->gatherYieldMultiplierFor($character['well_fed_until'] ?? null);
        if ($mult <= 1.0) {
            return $foundResources;
        }
        foreach ($foundResources as $i => $res) {
            $amtRaw = $res['amount'] ?? null;
            if (is_numeric($amtRaw)) {
                $foundResources[$i]['amount'] = max(1, (int) round(((int) $amtRaw) * $mult));
            }
        }
        return $foundResources;
    }

    /** @var list<array{name: string, amount: int}> Срезанное weight-cap'ом (для notify). */
    private array $weightCapSkipped = [];

    /**
     * ADR-085 Склад Фаза 1b — clamp добычи к остатку ёмкости (dormant до killswitch ON).
     * Привязывает вес (resources.weight) к каждому ресурсу и зовёт
     * WeightCapacityService::clampToCapacity. Срезанное запоминает для notifyWeightCapOverflow.
     * Killswitch OFF → возврат без изменений (byte-identical, 0 эффекта на живых).
     *
     * @param list<array<string,mixed>> $foundResources
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @return list<array<string,mixed>>
     */
    private function applyWeightCapClamp(array $foundResources, array|\App\Entities\CharacterEntity $character): array
    {
        $this->weightCapSkipped = [];
        $weightCap = new \App\Services\Player\WeightCapacityService();
        if (! $weightCap->isEnabled()) {
            return $foundResources; // dormant → no-op
        }

        // map resource_id => [weight, name]
        $meta = [];
        foreach ($this->resourceModel->findAllCached() as $entity) {
            $idRaw = $entity['id'] ?? null;
            if (! is_numeric($idRaw)) {
                continue;
            }
            $wRaw = $entity['weight'] ?? null;
            $meta[(int) $idRaw] = [
                'weight' => is_numeric($wRaw) ? (float) $wRaw : 0.0,
                'name'   => is_scalar($entity['name'] ?? null) ? (string) $entity['name'] : ('#' . (int) $idRaw),
            ];
        }

        $withWeight = [];
        foreach ($foundResources as $res) {
            $rid           = isset($res['resource_id']) && is_numeric($res['resource_id']) ? (int) $res['resource_id'] : 0;
            $res['weight'] = $meta[$rid]['weight'] ?? 0.0;
            $withWeight[]  = $res;
        }

        $charId = isset($character['id']) && is_numeric($character['id']) ? (int) $character['id'] : 0;
        $level  = isset($character['level']) && is_numeric($character['level']) ? (int) $character['level'] : 1;
        $wcRaw  = isset($character['weight_capacity']) && is_numeric($character['weight_capacity']) ? (int) $character['weight_capacity'] : 0;
        $wc     = $wcRaw >= 9999 ? 0 : $wcRaw; // 9999 = legacy sentinel «без cap» → формула

        $result = $weightCap->clampToCapacity($charId, $level, $wc, $withWeight);

        foreach ($result['skipped'] as $sk) {
            $rid = isset($sk['resource_id']) && is_numeric($sk['resource_id']) ? (int) $sk['resource_id'] : 0;
            $amt = isset($sk['amount']) && is_numeric($sk['amount']) ? (int) $sk['amount'] : 0;
            if ($amt > 0) {
                $this->weightCapSkipped[] = [
                    'name'   => $meta[$rid]['name'] ?? ('#' . $rid),
                    'amount' => $amt,
                ];
            }
        }

        /** @var list<array<string,mixed>> $kept */
        $kept = $result['kept'];
        return $kept;
    }

    /**
     * ADR-085 Склад Фаза 1b — уведомление о переполнении рюкзака (clamp+notify).
     * Шлёт только при наличии срезанного (т.е. killswitch ON). Dormant → no-op.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function notifyWeightCapOverflow(array|\App\Entities\CharacterEntity $character): void
    {
        if ($this->weightCapSkipped === []) {
            return;
        }
        $userRow = $this->telegramUserModel->where('id', $character['telegram_user_id'] ?? 0)->first();
        if (! is_array($userRow) || empty($userRow['telegram_id'])) {
            return;
        }
        $chatIdRaw = $userRow['telegram_id'];
        $chatId    = is_numeric($chatIdRaw) ? (int) $chatIdRaw : 0;
        if ($chatId === 0) {
            return;
        }
        $lines = [];
        foreach ($this->weightCapSkipped as $sk) {
            $lines[] = "• {$sk['name']} ×{$sk['amount']}";
        }
        $text = "🎒 *Рюкзак переполнен* — часть добычи не влезла:\n"
            . implode("\n", $lines)
            . "\n\nРазгрузись (продай излишки или перенеси в Склад) либо подними вместимость.";
        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }

    /**
     * V6 (ADR-033) — light seed-drop при сборе ресурсов (вторичный источник;
     * primary = крафт семян). Gated: farming.enabled + farming.seed_drop_chance.
     * Полностью изолировано и defensive — никогда не валит gather completion.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     */
    private function maybeDropSeed(array|\App\Entities\CharacterEntity $character): void
    {
        try {
            if (! $this->farming->isEnabled()) {
                return;
            }
            $chance = $this->farming->seedDropChance();
            if ($chance <= 0.0) {
                return;
            }
            $roll = mt_rand(1, 10000) / 10000.0;
            if ($roll > $chance) {
                return;
            }
            $crops = $this->farming->allCrops();
            if ($crops === []) {
                return;
            }
            $crop = $crops[array_rand($crops)];
            $meta = $this->farming->cropMeta($crop);
            if ($meta === null) {
                return;
            }
            $charIdRaw = $character['id'] ?? 0;
            $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;
            if ($charId <= 0) {
                return;
            }
            $this->resourceModel->addOrIncreaseResource($charId, $meta['seed_en'], 1);
            $this->notifySeedDrop($character, $meta);
        } catch (\Throwable $e) {
            log_message('error', '[GatherTaskHandler] maybeDropSeed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param array{seed_en:string, seed_ru:string, crop_en:string, crop_ru:string, icon:string, recipe:string, grow_key:string, grow_default:int, yield_key:string, yield_default:int} $meta
     */
    private function notifySeedDrop(array|\App\Entities\CharacterEntity $character, array $meta): void
    {
        $tgRaw   = $character['telegram_user_id'] ?? 0;
        $userRow = $this->telegramUserModel->where('id', is_numeric($tgRaw) ? (int) $tgRaw : 0)->first();
        if (!is_array($userRow) || empty($userRow['telegram_id'])) {
            return;
        }
        $chatIdRaw = $userRow['telegram_id'];
        $chatId    = is_numeric($chatIdRaw) ? (int) $chatIdRaw : 0;
        if ($chatId === 0) {
            return;
        }
        $text = "🌱 *Удача!* Среди добычи нашлись *{$meta['seed_ru']}*.\n"
            . "Посади их на грядках теплицы, чтобы вырастить {$meta['icon']} {$meta['crop_ru']}.";
        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }

    /**
     * Основной метод расчёта добытых ресурсов
     * (блочная логика по 10 минут + бонус от инструментов).
     *
     * @return list<array<string,mixed>>
     */
    protected function calculateFoundResources(
        array $resources,
        int $spentMinutes,
        array|\App\Entities\CharacterEntity $character,
        array $task,
        array $loadedEvents
    ): array {
        // ADR-090 «Мягкий старт»: soft-ramp rarity-доступа вместо бинарного гейта.
        // Killswitch OFF → жёсткий гейт как раньше (byte-identical).
        $level = (int) $character['level'];
        $ea    = $this->earlyAccessParams();

        $blocksCount = intdiv($spentMinutes, 10);
        $remainder   = $spentMinutes % 10;

        $baseBlockResources = [];
        foreach ($resources as $resource) {
            $rarity = (int) $resource['rarity'];
            $factor = $this->formulaService->rarityYieldFactor($level, $rarity, $ea['enabled'], $ea['window'], $ea['step']);
            if ($factor <= 0.0) {
                continue;
            }
            // Базовый выход за 10 мин × фактор доступа (1.0 для разблокированных,
            // step^tiersEarly для превью-тиров). Дробный — финальное округление позже.
            $baseFor10Min = $this->getBaseQuantityByRarity($rarity) * $factor;

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
    /**
     * @param list<string> $signatureNames V22: signature-ресурсы биома для UI-хинта.
     */
    protected function sendResourcesFoundReply(
        array $foundResources,
        array|\App\Entities\CharacterEntity $character,
        int $spentMinutes,
        string $biomeName,
        array $signatureNames = []
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

        // S4 (v0.51.186+): инструменты сломавшиеся в течение сессии — для
        // нотификации игроку. Раньше silent disappearance.
        $brokenTools = $this->toolDurability->getBrokenTools();

        // v0.51.104: pre-load tool map якщо є tools used.
        // S4: подгружаем и сломавшихся (могут не входить в usedToolsCount —
        // их increment делается только при $ok=true).
        $toolByName = [];
        $toolNames  = array_unique(array_merge(
            array_keys($this->usedToolsCount),
            array_keys($brokenTools)
        ));
        if (!empty($toolNames)) {
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
            $toolByName,
            $brokenTools,
            $signatureNames
        );

        $this->safeSendPhoto(
            $chatId,
            base_url('uploads/telegram/loot_resources_in_the_box.png'),
            $reply['text'],
            ['parse_mode' => 'HTML', 'reply_markup' => $reply['keyboard']]
        );
    }

}
