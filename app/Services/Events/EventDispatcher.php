<?php

namespace App\Services\Events;

use App\Models\ActiveEventModel;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\EventEffectsLogModel;
use App\Models\EventModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerStateService;
use Config\WorldEvents;
use Throwable;

/**
 * F7.3 — diспетчер для всех 24 world events.
 *
 * Замінюесть функциональність 19 hand-rolled handler'ів через один прохід:
 *   1. Прочитать active_events (1 SQL).
 *   2. Для кожного active event:
 *      - Resolve config с WorldEvents.php
 *      - Tick chance gate (legacy parity)
 *      - Resolve effect class через EffectResolver
 *      - Найти affected players (1 SQL per event)
 *      - Для кожного: build context + compute + apply intents + notify
 *
 * Вызовається с EventTickHandler::process() через Tasks.php scheduler
 * `event.tick` (everyMinute → singleInstance → один dispatcher вместо 19).
 *
 * Notification policy F7.3:
 *   - Временно сохранаємо tick-уведомлении (legacy fidelity).
 *   - Уніфікований формат: фото с img_path + log_summary.
 *   - F7.5 NotificationPolicy v2 переробить на start+end summary без tick spam.
 */
final class EventDispatcher
{
    private WorldEvents $cfg;
    private EventModel $eventModel;
    private ActiveEventModel $activeEventModel;
    private CharacterModel $charModel;
    private BiomeModel $biomeModel;
    private TelegramUserModel $tgUserModel;
    private MapModel $mapModel;
    private EventEffectsLogModel $logModel;
    private PlayerStateService $playerState;
    private IntentApplier $applier;
    private EffectAccumulator $accumulator;
    private CraftedItemsLogModel $craftedItemsLogModel;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?EventModel $eventModel = null,
        ?ActiveEventModel $activeEventModel = null,
        ?CharacterModel $charModel = null,
        ?BiomeModel $biomeModel = null,
        ?TelegramUserModel $tgUserModel = null,
        ?MapModel $mapModel = null,
        ?EventEffectsLogModel $logModel = null,
        ?PlayerStateService $playerState = null,
        ?IntentApplier $applier = null,
        ?EffectAccumulator $accumulator = null,
        ?CraftedItemsLogModel $craftedItemsLogModel = null,
    ) {
        $this->cfg              = $cfg              ?? config('WorldEvents');
        $this->eventModel       = $eventModel       ?? new EventModel();
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->charModel        = $charModel        ?? new CharacterModel();
        $this->biomeModel       = $biomeModel       ?? new BiomeModel();
        $this->tgUserModel      = $tgUserModel      ?? new TelegramUserModel();
        $this->mapModel         = $mapModel         ?? new MapModel();
        $this->logModel         = $logModel         ?? new EventEffectsLogModel();
        $this->playerState      = $playerState      ?? new PlayerStateService();
        $this->craftedItemsLogModel = $craftedItemsLogModel ?? new CraftedItemsLogModel();

        $this->applier = $applier ?? new IntentApplier(
            $this->charModel,
            new CharacterResourceModel(),
            new CharacterTaskModel(),
            new ResourceModel(),
            new TaskModel(),
        );

        $this->accumulator = $accumulator ?? new EffectAccumulator($this->activeEventModel);
    }

    /**
     * Entry point — вызовається с EventTickHandler.
     *
     * @return array<string, mixed> stats для логів: ['active_events' => N, 'players_affected' => M, ...]
     */
    public function tickAllActive(): array
    {
        $stats = [
            'active_events_total' => 0,
            'events_dispatched'   => 0,
            'players_evaluated'   => 0,
            'effects_applied'     => 0,
            'errors'              => 0,
        ];

        // 1. Active events
        $activeEvents = $this->activeEventModel
            ->where('status', 'active')
            ->where('end_time >=', date('Y-m-d H:i:s'))
            ->findAll();

        $stats['active_events_total'] = count($activeEvents);

        foreach ($activeEvents as $activeEvent) {
            try {
                $dispatched = $this->dispatchEvent($activeEvent, $stats);
                if ($dispatched) {
                    $stats['events_dispatched']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[EventDispatcher] event_id={$activeEvent['event_id']}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Dispatch single active event.
     */
    private function dispatchEvent(array $activeEvent, array &$stats): bool
    {
        $eventId = (int)$activeEvent['event_id'];

        // Резолвинг канонічного event row для name_english + biome_ids + img_path
        $eventRow = $this->eventModel->find($eventId);
        if (!$eventRow) {
            log_message('warning', "[EventDispatcher] event_id={$eventId} отсутствій в events");
            return false;
        }

        $nameEn = $eventRow['name_english'];
        $config = $this->cfg->get($nameEn);

        if ($config === null) {
            log_message('warning', "[EventDispatcher] event '{$nameEn}' немаесть в WorldEvents.php config");
            return false;
        }

        // Tick chance gate (legacy parity)
        $tickChance = (float)($config['tick_chance'] ?? 1.0);
        if ($tickChance < 1.0 && (mt_rand(1, 10000) / 10000.0) > $tickChance) {
            return false;  // skip silently
        }

        // Resolve effect class
        $effect = EffectResolver::resolve($config['effect_kind']);

        // Найти affected players
        $players = $this->findAffectedPlayers($eventRow);

        // Збагатити activeEvent даними с canonical events row (effect_value)
        $enrichedActive = array_merge($activeEvent, [
            'effect_value' => $eventRow['effect_value'] ?? null,
            'name'         => $eventRow['name'],
            'name_english' => $nameEn,
            'img_path'     => $eventRow['img_path'] ?? null,
        ]);

        $protectionItem = $config['protection_item'] ?? null;

        foreach ($players as $char) {
            $stats['players_evaluated']++;

            try {
                $context = $this->buildContext($char, $protectionItem);
                $result  = $effect->compute($char, $config, $enrichedActive, $context);

                if ($result['applied'] ?? false) {
                    // Immediate apply (game state коректний в real-time)
                    $this->applier->apply($char, $result);
                    $this->handleRevealCells($char, $result);
                    // Audit log (історія всех дій)
                    $this->logToDb($char, $eventId, $result);
                    // F7.4: накопичуємо для end-summary (вместо tick-уведомлении)
                    $this->accumulator->accumulate(
                        (int)$activeEvent['id'],
                        (int)$char['id'],
                        $result
                    );
                    $stats['effects_applied']++;
                }
                // F7.4: tick-уведомлении убрано. End-summary с агрегатом
                // посылааесть EventCloseHandler когда event переходить в completed.
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[EventDispatcher] char_id={$char['id']}: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Найти characters в biomes events.biome_ids (або global = ALL).
     *
     * @return list<array<string, mixed>>
     */
    private function findAffectedPlayers(array $eventRow): array
    {
        $biomesJson = $eventRow['biome_ids'] ?? null;
        $biomes     = json_decode($biomesJson, true);

        if (!is_array($biomes) || empty($biomes)) {
            // Global: все characters
            return $this->charModel->findAll();
        }

        // Local: characters в обраних биомах
        return $this->charModel->whereIn('biome_id', $biomes)->findAll();
    }

    /**
     * Собрать context для effect.compute() — на одномв игроку.
     *
     * @param array<string, mixed> $char
     * @param ?string $protectionItem name_eng с WorldEvents config (F7.7)
     * @return array<string, mixed>
     */
    private function buildContext(array $char, ?string $protectionItem = null): array
    {
        $charId = (int)$char['id'];

        $isGather  = $this->playerState->isGathering($charId);
        $isExplore = $this->playerState->isExploring($charId);
        $onBase    = $this->playerState->isCharacterOnBase($charId);

        $biome = $char['biome_id'] ? $this->biomeModel->find($char['biome_id']) : null;

        // F7.7: проверити или есть protection_item в інвентари (-50% damage в effect)
        $hasProtection = false;
        if ($protectionItem !== null && $protectionItem !== '') {
            $row = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($protectionItem, $charId);
            $hasProtection = $row !== null && (int)($row['quantity'] ?? 0) > 0;
        }

        return [
            'on_base'              => $onBase,
            'is_gathering'         => $isGather,
            'is_exploring'         => $isExplore,
            'biome'                => $biome,
            'now_time'             => date('H:i'),
            'last_seen_hours_ago'  => null,  // F7.6: додати telegram_users.last_seen
            'has_protection_item'  => $hasProtection,
        ];
    }

    /**
     * MountainEcho — вызоваесть mapModel->getSurroundingCells() с compute-intent.
     */
    private function handleRevealCells(array $char, array $result): void
    {
        $intent = $result['reveal_cells_intent'] ?? null;
        if ($intent === null) {
            return;
        }

        $count = (int)($intent['count'] ?? 0);
        if ($count <= 0) {
            return;
        }

        // Вызов MapModel — побічний ефект (відкриваесть cells), результат
        // используется dispatcher'ом для UX-уведомлении.
        $cells = $this->mapModel->getSurroundingCells($char['cell_number'], $count);

        // Відмітимо cells в результати, щоб notification міг показати coords
        // (поки що — log_summary уже готовий).
        unset($cells);
    }

    /**
     * Лог в event_effects_log (унифіковано для всех effect kinds).
     */
    private function logToDb(array $char, int $eventId, array $result): void
    {
        try {
            $this->logModel->insert([
                'character_id'   => (int)$char['id'],
                'event_id'       => $eventId,
                'effect_details' => json_encode([
                    'health_delta'     => $result['health_delta']     ?? 0,
                    'tired_delta'      => $result['tired_delta']      ?? 0,
                    'gold_delta'       => $result['gold_delta']       ?? 0,
                    'attribute_deltas' => $result['attribute_deltas'] ?? [],
                    'log_summary'      => $result['log_summary']      ?? '',
                    'magnitude'        => $result['magnitude']        ?? [],
                ], JSON_UNESCAPED_UNICODE),
                'event_time'     => date('Y-m-d H:i:s'),
                'cell_number'    => $char['cell_number'] ?? null,
                'biome_id'       => $char['biome_id']    ?? null,
            ]);
        } catch (Throwable $e) {
            log_message('error', "[EventDispatcher] log insert failed: " . $e->getMessage());
        }
    }

    // F7.4: sendTickNotification + ensureTelegramInitialized + resolveTelegramId
    // переихали в EventCloseHandler (формуесть end-summary при завершенни подіи).
    // EventDispatcher больше не торкається Telegram'в на tick'ах.
}
