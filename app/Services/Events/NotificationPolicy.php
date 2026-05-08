<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use Config\WorldEvents;
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
 * v0.51.72 (decomp 5/5 closed) — orchestrator з 5 SRP services:
 *   EventPreferenceService    pref/mute/throttle/magnitude logic
 *   EventMessageFormatter     Markdown templates + inline keyboards
 *   EventRecipientFinder      sectoring + recipient lookup
 *   EventNotificationSender   Telegram I/O (єдина точка)
 *   + NotificationPolicy itself — orchestrator
 *
 * @see WorldEvents для $criticalMagnitudeTriggers + $notificationThrottleMinutes
 */
final class NotificationPolicy
{
    private TelegramUserModel $tgUserModel;
    private EventPreferenceService $prefService;
    private EventMessageFormatter $formatter;
    private EventRecipientFinder $recipientFinder;
    private EventNotificationSender $sender;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?CharacterModel $charModel = null,
        ?TelegramUserModel $tgUserModel = null,
        ?EventPreferenceService $prefService = null,
        ?EventMessageFormatter $formatter = null,
        ?EventRecipientFinder $recipientFinder = null,
        ?EventNotificationSender $sender = null,
    ) {
        $cfg                   = $cfg             ?? config('WorldEvents');
        $charModel             = $charModel       ?? new CharacterModel();
        $this->tgUserModel     = $tgUserModel     ?? new TelegramUserModel();
        $this->prefService     = $prefService     ?? new EventPreferenceService($cfg, $this->tgUserModel);
        $this->formatter       = $formatter       ?? new EventMessageFormatter();
        $this->recipientFinder = $recipientFinder ?? new EventRecipientFinder($charModel);
        $this->sender          = $sender          ?? new EventNotificationSender();
    }

    /**
     * Отправить start-уведомление (sectored + throttled).
     *
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $eventConfig
     * @return array{sent: int, skipped_throttle: int, skipped_mute: int, skipped_no_chat: int, errors: int}
     */
    public function sendStart(array $eventRow, array $eventConfig): array
    {
        $stats = ['sent' => 0, 'skipped_throttle' => 0, 'skipped_mute' => 0, 'skipped_no_chat' => 0, 'errors' => 0];

        $biomeIds   = $this->recipientFinder->resolveBiomeIds($eventRow);
        $recipients = $this->recipientFinder->findRecipientChars($biomeIds);

        $effectKind = $eventConfig['effect_kind'] ?? '';
        $message    = $this->formatter->buildStartMessage($eventRow, $eventConfig);
        $imgPath    = $eventRow['img_path'] ?? null;
        $keyboard   = $this->formatter->buildStartKeyboard($effectKind);

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
                $sent = $this->sender->send((int)$tgUser['telegram_id'], $message, $imgPath, $keyboard);
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
     * @param array<string, mixed>|\App\Entities\CharacterEntity $char
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $eventConfig
     * @param array<string, mixed> $aggregate
     * @return bool true если сообщение отправлено (false = skipped/error)
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

        $message  = $this->formatter->buildEndMessage($eventRow, $aggregate);
        $keyboard = $this->formatter->buildEndKeyboard($effectKind, $aggregate);

        try {
            $sent = $this->sender->send((int)$tgUser['telegram_id'], $message, null, $keyboard);
            if ($sent) {
                $this->prefService->recordSent((int)$tgUser['id'], $pref);
            }
            return $sent;
        } catch (Throwable $e) {
            log_message('error', "[NotificationPolicy] sendEnd char={$char['id']}: " . $e->getMessage());
            return false;
        }
    }
}
