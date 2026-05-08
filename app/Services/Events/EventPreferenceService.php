<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\TelegramUserModel;
use Config\WorldEvents;

/**
 * v0.51.68 (NotificationPolicy decomp Step 1) — extract pref/mute/throttle/
 * magnitude logic у dedicated service.
 *
 * Public API:
 *   readUserPref(tgUser): array       — resilient JSON decode (NULL/'' / invalid → [])
 *   isMuted(pref, effectKind=''): bool — silenced_until OR muted_kinds check
 *   isThrottled(pref): bool            — last_event_notification_at vs cfg.throttleMinutes
 *   magnitudeOverrides(magOrAgg): bool — critical thresholds (HP/resource/gold/rare/health-after)
 *   recordSent(tgUserId, pref): void   — UPDATE telegram_users.event_pref
 *
 * Pure-logic частини (1-4) testable без DB — recordSent потребує model.
 *
 * @see WorldEvents для $criticalMagnitudeTriggers + $notificationThrottleMinutes
 */
final class EventPreferenceService
{
    private WorldEvents $cfg;
    private TelegramUserModel $tgUserModel;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?TelegramUserModel $tgUserModel = null,
    ) {
        $this->cfg         = $cfg         ?? config('WorldEvents');
        $this->tgUserModel = $tgUserModel ?? new TelegramUserModel();
    }

    /**
     * Прочитати event_pref з telegram_user row (resilient до NULL/невалідного JSON).
     *
     * @param array<string, mixed> $tgUser
     * @return array<string, mixed>
     */
    public function readUserPref(array $tgUser): array
    {
        $raw = $tgUser['event_pref'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Перевірити чи muted (silenced_until активний АБО kind у muted_kinds).
     *
     * @param array<string, mixed> $pref
     */
    public function isMuted(array $pref, string $effectKind = ''): bool
    {
        $silencedUntil = $pref['silenced_until'] ?? null;
        if ($silencedUntil !== null && strtotime($silencedUntil) > time()) {
            return true;
        }

        $mutedKinds = (array)($pref['muted_kinds'] ?? []);
        if ($effectKind !== '' && in_array($effectKind, $mutedKinds, true)) {
            return true;
        }

        return false;
    }

    /**
     * Чи юзер throttled (відправляли нотіфікацію менше cfg.notificationThrottleMinutes тому).
     *
     * @param array<string, mixed> $pref
     */
    public function isThrottled(array $pref): bool
    {
        $lastAt = $pref['last_event_notification_at'] ?? null;
        if ($lastAt === null) {
            return false;
        }
        $thrSec = $this->cfg->notificationThrottleMinutes * 60;
        return (time() - strtotime($lastAt)) < $thrSec;
    }

    /**
     * Чи magnitude події перевищує critical threshold (override throttle).
     *
     * Працює з aggregate (з accumulator) АБО з single-tick magnitude (з compute).
     *
     * @param array<string, mixed> $magnitudeOrAggregate
     */
    public function magnitudeOverrides(array $magnitudeOrAggregate): bool
    {
        $triggers = $this->cfg->criticalMagnitudeTriggers;

        // 1) HP loss percent
        $hpLossPct = (float)($magnitudeOrAggregate['health_loss_percent'] ?? 0);
        if ($hpLossPct >= (float)$triggers['health_loss_percent_above']) {
            return true;
        }

        // 2) Resource loss percent
        $resLossPct = (float)($magnitudeOrAggregate['resource_loss_percent'] ?? 0);
        if ($resLossPct >= (float)$triggers['resource_loss_percent_above']) {
            return true;
        }

        // 3) Gold gain
        $goldGain = (int)($magnitudeOrAggregate['gold_gain'] ?? $magnitudeOrAggregate['gold_delta'] ?? 0);
        if ($goldGain >= (int)$triggers['gold_gain_above']) {
            return true;
        }

        // 4) Rare item drop / resource grants
        if (!empty($magnitudeOrAggregate['rare_item_drop']) || !empty($magnitudeOrAggregate['resource_grants'])) {
            return true;
        }

        // 5) Health after critical
        $hpAfter = $magnitudeOrAggregate['health_after'] ?? null;
        if ($hpAfter !== null && (float)$hpAfter < (float)$triggers['health_after_below']) {
            return true;
        }

        return false;
    }

    /**
     * Записати last_event_notification_at в event_pref.
     *
     * @param array<string, mixed> $pref
     */
    public function recordSent(int $tgUserId, array $pref): void
    {
        $pref['last_event_notification_at'] = date('Y-m-d H:i:s');
        $this->tgUserModel->update($tgUserId, [
            'event_pref' => json_encode($pref, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
