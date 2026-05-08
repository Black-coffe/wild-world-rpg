<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Config\WorldEvents;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * F7.5 — центральная политика анти-спам уведомлений для world events.
 *
 * Управляет ВСЕ event-related Telegram messaging:
 *   - sendStart() : однократное сообщение про новый event (только игрокам у
 *                   affected biomes — sectoring вместо broadcast)
 *   - sendEnd()   : однократное end-summary с агрегатом (с EventCloseHandler)
 *
 * Правила анти-спаму:
 *   - **Sectoring**: для local событий шлемо только игрокам с biome_id в event.biome_ids;
 *     для global (все 9 биомов) — все ж равно broadcast.
 *   - **Throttle**: 1 уведомления / пользователь / час (за `event_pref.last_event_notification_at`).
 *     Override если magnitude >= critical thresholds (HP loss > 25%, gold > 5000, ...).
 *   - **Mute**: если `event_pref.silenced_until > NOW` → skip всех уведомлений.
 *   - **Kind-mute**: если `effect_kind` в `event_pref.muted_kinds` → skip.
 *
 * Структура `event_pref`:
 * ```json
 * {
 *     "last_event_notification_at": "2026-05-05 14:30:00",
 *     "silenced_until": "2026-05-05 18:00:00",
 *     "muted_kinds": ["damage_health"]
 * }
 * ```
 *
 * @see WorldEvents для $criticalMagnitudeTriggers + $notificationThrottleMinutes
 */
final class NotificationPolicy
{
    private WorldEvents $cfg;
    private CharacterModel $charModel;
    private TelegramUserModel $tgUserModel;
    private BiomeModel $biomeModel;
    private EventPreferenceService $prefService;
    private ?Telegram $telegram = null;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?CharacterModel $charModel = null,
        ?TelegramUserModel $tgUserModel = null,
        ?BiomeModel $biomeModel = null,
        ?EventPreferenceService $prefService = null,
    ) {
        $this->cfg         = $cfg         ?? config('WorldEvents');
        $this->charModel   = $charModel   ?? new CharacterModel();
        $this->tgUserModel = $tgUserModel ?? new TelegramUserModel();
        $this->biomeModel  = $biomeModel  ?? new BiomeModel();
        $this->prefService = $prefService ?? new EventPreferenceService($this->cfg, $this->tgUserModel);
    }

    // ============================================================
    // Public API: sendStart / sendEnd
    // ============================================================

    /**
     * Отправить start-уведомлению (sectored + throttled).
     *
     * @return array{sent: int, skipped_throttle: int, skipped_mute: int, skipped_no_chat: int, errors: int}
     */
    public function sendStart(array $eventRow, array $eventConfig): array
    {
        $stats = ['sent' => 0, 'skipped_throttle' => 0, 'skipped_mute' => 0, 'skipped_no_chat' => 0, 'errors' => 0];

        $biomeIds   = $this->resolveBiomeIds($eventRow);
        $recipients = $this->findRecipientChars($biomeIds);

        $effectKind = $eventConfig['effect_kind'] ?? '';
        $message    = $this->buildStartMessage($eventRow, $eventConfig);
        $imgPath    = $eventRow['img_path'] ?? null;
        $keyboard   = $this->buildStartKeyboard($effectKind);

        foreach ($recipients as $char) {
            try {
                $tgUser = $this->tgUserModel->find($char['telegram_user_id'] ?? 0);
                if (!$tgUser || empty($tgUser['telegram_id'])) {
                    $stats['skipped_no_chat']++;
                    continue;
                }

                $pref = $this->prefService->readUserPref($tgUser);

                if ($this->prefService->isMuted($pref, $effectKind)) {
                    $stats['skipped_mute']++;
                    continue;
                }

                // Start-уведомлении НЕ throttle'ються (це разова событие, не tick'и).
                // Throttle больше актуальний для end-summary в спамерских сценариях.
                $sent = $this->doSend((int)$tgUser['telegram_id'], $message, $imgPath, $keyboard);
                if ($sent) {
                    $this->prefService->recordSent((int)$tgUser['id'], $pref);
                    $stats['sent']++;
                } else {
                    $stats['errors']++;
                }

                // 35ms throttle для Telegram global rate-limit (~28 msg/sec)
                if ($stats['sent'] > 0 && $stats['sent'] % 1 === 0) {
                    usleep(35000);
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[NotificationPolicy] sendStart char={$char['id']}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Отправить end-summary с аккумулированими deltas.
     *
     * @return bool true если сообщение отправлено (false = skipped/error)
     */
    /**
     * @param array<string, mixed>|\App\Entities\CharacterEntity $char
     */
    public function sendEnd(array|\App\Entities\CharacterEntity $char, array $eventRow, array $eventConfig, array $aggregate): bool
    {
        $tgUser = $this->tgUserModel->find($char['telegram_user_id'] ?? 0);
        if (!$tgUser || empty($tgUser['telegram_id'])) {
            return false;
        }

        $pref       = $this->prefService->readUserPref($tgUser);
        $effectKind = $eventConfig['effect_kind'] ?? '';

        if ($this->prefService->isMuted($pref, $effectKind)) {
            return false;
        }

        // End-summary throttle: skip если недавно уже отправляли,
        // КРОМЕ случав когда magnitude critical (override).
        if (!$this->prefService->magnitudeOverrides($aggregate) && $this->prefService->isThrottled($pref)) {
            return false;
        }

        $message  = $this->buildEndMessage($eventRow, $aggregate);
        $keyboard = $this->buildEndKeyboard($effectKind, $aggregate);

        try {
            $sent = $this->doSend((int)$tgUser['telegram_id'], $message, null, $keyboard);
            if ($sent) {
                $this->prefService->recordSent((int)$tgUser['id'], $pref);
            }
            return $sent;
        } catch (Throwable $e) {
            log_message('error', "[NotificationPolicy] sendEnd char={$char['id']}: " . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // Pref / throttle helpers
    // ============================================================

    /**
     * Public API delegations до EventPreferenceService (preserve test contract — 18 tests
     * у NotificationPolicyTest залишаються green без зміни). Step 5 polish: оцінити
     * чи варто drop'ати wrappers і update tests на direct EventPreferenceService.
     */
    public function readUserPref(array $tgUser): array
    {
        return $this->prefService->readUserPref($tgUser);
    }

    public function isMuted(array $pref, string $effectKind = ''): bool
    {
        return $this->prefService->isMuted($pref, $effectKind);
    }

    public function isThrottled(array $pref): bool
    {
        return $this->prefService->isThrottled($pref);
    }

    public function magnitudeOverrides(array $magnitudeOrAggregate): bool
    {
        return $this->prefService->magnitudeOverrides($magnitudeOrAggregate);
    }

    public function recordSent(int $tgUserId, array $pref): void
    {
        $this->prefService->recordSent($tgUserId, $pref);
    }

    // ============================================================
    // Sectoring
    // ============================================================

    /**
     * Резолвинг biome_ids с events.biome_ids JSON. Empty array означает global → ALL biomes.
     *
     * @return list<int>
     */
    private function resolveBiomeIds(array $eventRow): array
    {
        $raw = $eventRow['biome_ids'] ?? null;
        $arr = json_decode($raw, true);
        if (!is_array($arr) || empty($arr)) {
            return [];
        }
        return array_map('intval', $arr);
    }

    /**
     * Найти characters в зазначених biomes (або всех если порожній список = global).
     *
     * @param list<int> $biomeIds
     * @return list<array<string, mixed>>
     */
    private function findRecipientChars(array $biomeIds): array
    {
        if (empty($biomeIds)) {
            return $this->charModel->findAll();
        }
        return $this->charModel->whereIn('biome_id', $biomeIds)->findAll();
    }

    // ============================================================
    // Message building
    // ============================================================

    private function buildStartMessage(array $eventRow, array $eventConfig): string
    {
        $name        = $eventRow['name']        ?? $eventRow['name_english'];
        $description = $eventRow['description'] ?? '';
        $duration    = (int)($eventConfig['duration_minutes'] ?? $eventRow['duration'] ?? 60);

        $msg  = "⚠️ *Событие: {$name}*\n\n";
        if ($description !== '') {
            $msg .= "_{$description}_\n\n";
        }
        $msg .= "⏳ Продлится приблизительно: {$duration} мин.\n";
        $msg .= "_Сводкв отправим когда событие закончится._";

        return $msg;
    }

    private function buildEndMessage(array $eventRow, array $aggregate): string
    {
        $name       = $eventRow['name'] ?? $eventRow['name_english'];
        $appliedCnt = (int)($aggregate['applied_count'] ?? 0);
        $lines      = ["✅ *Событие завершилось: {$name}*\n"];
        $lines[]    = "_За {$appliedCnt} тиков действовало на тебя. Сводка:_\n";

        $hd = (float)($aggregate['health_delta'] ?? 0);
        $td = (float)($aggregate['tired_delta']  ?? 0);
        $gd = (int)  ($aggregate['gold_delta']   ?? 0);

        if ($hd < 0) {
            $lines[] = "❤️ Здоровье: " . round($hd, 2);
        } elseif ($hd > 0) {
            $lines[] = "❤️ Здоровье: +" . round($hd, 2);
        }
        if ($td < 0) {
            $lines[] = "💪 Выносливость: " . round($td, 2);
        } elseif ($td > 0) {
            $lines[] = "💪 Выносливость: +" . round($td, 2);
        }
        if ($gd > 0) {
            $lines[] = "🪙 Золото: +{$gd}";
        }

        foreach (($aggregate['attribute_deltas'] ?? []) as $attr => $delta) {
            $sign     = $delta > 0 ? '+' : '';
            $deltaStr = round((float)$delta, 2);
            $lines[]  = "📈 {$attr}: {$sign}{$deltaStr}";
        }

        if (!empty($aggregate['resource_grants'])) {
            $byKw = [];
            foreach ($aggregate['resource_grants'] as $g) {
                $kw         = $g['keyword'] ?? '?';
                $byKw[$kw]  = ($byKw[$kw] ?? 0) + (int)($g['amount'] ?? 0);
            }
            foreach ($byKw as $kw => $amt) {
                $lines[] = "🎁 {$kw}: +{$amt}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Кнопки для start-уведомлении.
     *
     * Callback formats (F7.5b — handler EventPrefAction):
     *   - 'eventPref_mute_1h'              — silenced_until +1h
     *   - 'eventPref_muteKind_<kind>'      — toggle kind в muted_kinds
     *
     * Двоеточия `:` НЕ используем — Telegram передает, але CallbackRouter
     * матчить через `_*` wildcard. Underscores в kind ('damage_health') —
     * EventPrefAction парсить весь суффикс после prefix'а.
     */
    private function buildStartKeyboard(string $effectKind): array
    {
        $kindLabel = KindLabels::ru($effectKind);

        return [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events'],
                ],
                [
                    ['text' => '🚫 Не показывать 1 час', 'callback_data' => 'eventPref_mute_1h'],
                    ['text' => "🚫 Без событий {$kindLabel}", 'callback_data' => "eventPref_muteKind_{$effectKind}"],
                ],
            ],
        ];
    }

    /**
     * Кнопки для end-summary. Magnitude-based — додаткова кнопка лікування при HP critical.
     */
    private function buildEndKeyboard(string $effectKind, array $aggregate): array
    {
        $rows = [
            [
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ['text' => '🎉 События',       'callback_data' => 'events'],
            ],
        ];

        // Додаткова кнопка если HP loss значний
        $hd = (float)($aggregate['health_delta'] ?? 0);
        if ($hd < -10) {
            $rows[] = [
                ['text' => '🩹 Лечиться', 'callback_data' => 'craftBandage'],
            ];
        }

        $rows[] = [
            ['text' => '🚫 Не показывать 1 час', 'callback_data' => 'eventPref_mute_1h'],
        ];

        return ['inline_keyboard' => $rows];
    }

    // ============================================================
    // Telegram I/O (единствена точка)
    // ============================================================

    /**
     * Универсальний send: photo если img_path, иначе text.
     */
    private function doSend(int $chatId, string $text, ?string $imgPath, array $keyboard): bool
    {
        $this->ensureTelegramInitialized();

        try {
            if ($imgPath !== null && $imgPath !== '') {
                $photoFs = FCPATH . $imgPath;
                if (is_readable($photoFs)) {
                    Request::sendPhoto([
                        'chat_id'      => $chatId,
                        'photo'        => Request::encodeFile($photoFs),
                        'caption'      => $text,
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode($keyboard),
                    ]);
                    return true;
                }
                // Photo missing → fallback на text без зображення (resilient)
                log_message('warning', "[NotificationPolicy] photo missing: {$photoFs}");
            }

            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
            return true;
        } catch (TelegramException $e) {
            log_message('error', "[NotificationPolicy] doSend chat={$chatId}: " . $e->getMessage());
            return false;
        }
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
            log_message('error', "[NotificationPolicy] Telegram init failed: " . $e->getMessage());
        }
    }
}
