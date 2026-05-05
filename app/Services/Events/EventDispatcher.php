<?php

namespace App\Services\Events;

use App\Models\ActiveEventModel;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\EventEffectsLogModel;
use App\Models\EventModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerStateService;
use Config\WorldEvents;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * F7.3 — diспетчер для всіх 24 world events.
 *
 * Замінює функціональність 19 hand-rolled handler'ів через один прохід:
 *   1. Прочитати active_events (1 SQL).
 *   2. Для кожного active event:
 *      - Resolve config з WorldEvents.php
 *      - Tick chance gate (legacy parity)
 *      - Resolve effect class через EffectResolver
 *      - Знайти affected players (1 SQL per event)
 *      - Для кожного: build context + compute + apply intents + notify
 *
 * Викликається з EventTickHandler::process() через Tasks.php scheduler
 * `event.tick` (everyMinute → singleInstance → один dispatcher замість 19).
 *
 * Notification policy F7.3:
 *   - Тимчасово зберігаємо tick-нотіфікації (legacy fidelity).
 *   - Уніфікований формат: фото з img_path + log_summary.
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
    private ?Telegram $telegram = null;

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

        $this->applier = $applier ?? new IntentApplier(
            $this->charModel,
            new CharacterResourceModel(),
            new CharacterTaskModel(),
            new ResourceModel(),
            new TaskModel(),
        );
    }

    /**
     * Entry point — викликається з EventTickHandler.
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

        // Резолв канонічного event row для name_english + biome_ids + img_path
        $eventRow = $this->eventModel->find($eventId);
        if (!$eventRow) {
            log_message('warning', "[EventDispatcher] event_id={$eventId} відсутній у events");
            return false;
        }

        $nameEn = $eventRow['name_english'];
        $config = $this->cfg->get($nameEn);

        if ($config === null) {
            log_message('warning', "[EventDispatcher] event '{$nameEn}' немає в WorldEvents.php config");
            return false;
        }

        // Tick chance gate (legacy parity)
        $tickChance = (float)($config['tick_chance'] ?? 1.0);
        if ($tickChance < 1.0 && (mt_rand(1, 10000) / 10000.0) > $tickChance) {
            return false;  // skip silently
        }

        // Resolve effect class
        $effect = EffectResolver::resolve($config['effect_kind']);

        // Знайти affected players
        $players = $this->findAffectedPlayers($eventRow);

        // Збагатити activeEvent даними з canonical events row (effect_value)
        $enrichedActive = array_merge($activeEvent, [
            'effect_value' => $eventRow['effect_value'] ?? null,
            'name'         => $eventRow['name'],
            'name_english' => $nameEn,
            'img_path'     => $eventRow['img_path'] ?? null,
        ]);

        foreach ($players as $char) {
            $stats['players_evaluated']++;

            try {
                $context = $this->buildContext($char);
                $result  = $effect->compute($char, $config, $enrichedActive, $context);

                if ($result['applied'] ?? false) {
                    $this->applier->apply($char, $result);
                    $this->handleRevealCells($char, $result);
                    $this->logToDb($char, $eventId, $result);
                    $this->sendTickNotification($char, $enrichedActive, $config, $result);
                    $stats['effects_applied']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[EventDispatcher] char_id={$char['id']}: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Знайти characters в biomes events.biome_ids (або global = ALL).
     *
     * @return list<array<string, mixed>>
     */
    private function findAffectedPlayers(array $eventRow): array
    {
        $biomesJson = $eventRow['biome_ids'] ?? null;
        $biomes     = json_decode($biomesJson, true);

        if (!is_array($biomes) || empty($biomes)) {
            // Global: усі characters
            return $this->charModel->findAll();
        }

        // Local: characters в обраних біомах
        return $this->charModel->whereIn('biome_id', $biomes)->findAll();
    }

    /**
     * Зібрати context для effect.compute() — на одному гравцеві.
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $char): array
    {
        $charId = (int)$char['id'];

        $isGather  = $this->playerState->isGathering($charId);
        $isExplore = $this->playerState->isExploring($charId);
        $onBase    = $this->playerState->isCharacterOnBase($charId);

        $biome = $char['biome_id'] ? $this->biomeModel->find($char['biome_id']) : null;

        return [
            'on_base'              => $onBase,
            'is_gathering'         => $isGather,
            'is_exploring'         => $isExplore,
            'biome'                => $biome,
            'now_time'             => date('H:i'),
            'last_seen_hours_ago'  => null,  // F7.6: додати telegram_users.last_seen
            'has_protection_item'  => false, // F7.7: hook
        ];
    }

    /**
     * MountainEcho — викликає mapModel->getSurroundingCells() з compute-intent.
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

        // Виклик MapModel — побічний ефект (відкриває cells), результат
        // використовується dispatcher'ом для UX-нотіфікації.
        $cells = $this->mapModel->getSurroundingCells($char['cell_number'], $count);

        // Відмітимо cells у результаті, щоб notification міг показати coords
        // (поки що — log_summary вже готовий).
        unset($cells);
    }

    /**
     * Лог в event_effects_log (унифіковано для всіх effect kinds).
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

    /**
     * F7.3 — уніфікована tick notification. F7.5 переробить на start+end summary.
     */
    private function sendTickNotification(array $char, array $activeEvent, array $config, array $result): void
    {
        $tgId = $this->resolveTelegramId($char);
        if ($tgId === null) {
            return;
        }

        // F7.3: пропускаємо нотіфікації якщо log_summary порожній (skipped)
        $summary = trim($result['log_summary'] ?? '');
        if ($summary === '' || str_starts_with($summary, '[skipped]')) {
            return;
        }

        $name = $activeEvent['name'] ?? $activeEvent['name_english'];
        $text = "⚠️ *{$name}*\n\n{$summary}\n\n_Подія активна. Подробиці у меню «🎉 События»._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events'],
                ],
            ],
        ];

        $this->ensureTelegramInitialized();

        $imgPath = $activeEvent['img_path'] ?? null;
        try {
            if ($imgPath) {
                Request::sendPhoto([
                    'chat_id'      => $tgId,
                    'photo'        => Request::encodeFile(FCPATH . $imgPath),
                    'caption'      => $text,
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]);
            } else {
                Request::sendMessage([
                    'chat_id'      => $tgId,
                    'text'         => $text,
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => json_encode($keyboard),
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "[EventDispatcher] sendNotification failed: " . $e->getMessage());
        }
    }

    private function resolveTelegramId(array $char): ?int
    {
        $tgUserId = $char['telegram_user_id'] ?? null;
        if (!$tgUserId) {
            return null;
        }
        $tgUser = $this->tgUserModel->find($tgUserId);
        return $tgUser ? (int)$tgUser['telegram_id'] : null;
    }

    private function ensureTelegramInitialized(): void
    {
        if ($this->telegram !== null) {
            return;
        }
        try {
            $this->telegram = new Telegram(getenv('telegram.API_KEY'), getenv('telegram.BOT_USERNAME'));
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', "[EventDispatcher] Telegram init failed: " . $e->getMessage());
        }
    }
}
