<?php

namespace App\Services\Player\TeleportBeacon;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\TeleportBeaconModel;

/**
 * v0.51.55 (TeleportBeaconSetAction decomp Step 4) — extract capture flow
 * + old-owner notify lookup у dedicated service.
 *
 * Public API:
 *   captureBeacon(beaconId, captorCharId): array{
 *     status: 'not_found' | 'self_capture' | 'captured',
 *     beacon?: array,             // updated row (status='captured')
 *     oldOwnerCharId?: int|null   // null якщо stale orphan beacon
 *   }
 *
 *   lookupOwnerTelegramId(charId): ?int
 *     // returns telegram_id або null якщо нема character/telegram_users mapping
 *
 * Bonus cleanup: original notifyOldOwner використовував raw `db_connect()`
 * запит до telegram_users. Замінено на TelegramUserModel чистий ORM call.
 */
class BeaconCaptureService
{
    private TeleportBeaconModel $teleportBeaconModel;
    private CharacterModel $characterModel;
    private TelegramUserModel $telegramUserModel;

    public function __construct(
        ?TeleportBeaconModel $teleportBeaconModel = null,
        ?CharacterModel $characterModel = null,
        ?TelegramUserModel $telegramUserModel = null
    ) {
        $this->teleportBeaconModel = $teleportBeaconModel ?? new TeleportBeaconModel();
        $this->characterModel      = $characterModel      ?? new CharacterModel();
        $this->telegramUserModel   = $telegramUserModel   ?? new TelegramUserModel();
    }

    /**
     * Виконує захват маяка з усіма edge cases.
     *
     * @return array<string,mixed> з ключем `status`:
     *   - 'not_found': beacon відсутній / уже видалений
     *   - 'self_capture': captor — поточний owner (не змінюємо нічого)
     *   - 'captured': успішно змінили ownership; додає `beacon` + `oldOwnerCharId`
     */
    public function captureBeacon(int $beaconId, int $captorCharId): array
    {
        $beaconRow = $this->teleportBeaconModel->find($beaconId);
        if (!$beaconRow) {
            return ['status' => 'not_found'];
        }

        $currentOwner = (int) $beaconRow['character_id'];
        if ($currentOwner === $captorCharId) {
            return ['status' => 'self_capture'];
        }

        // Update ownership: change character_id + mark як 'captured'
        $this->teleportBeaconModel->update($beaconId, [
            'character_id'   => $captorCharId,
            'ownership_type' => 'captured',
        ]);

        // Fresh row для return (beacon з updated character_id)
        // Caller форматує message based on це.
        $beaconRow['character_id']   = $captorCharId;
        $beaconRow['ownership_type'] = 'captured';

        return [
            'status'         => 'captured',
            'beacon'         => $beaconRow,
            'oldOwnerCharId' => $currentOwner,
        ];
    }

    /**
     * Lookup telegram_id для notifying. Replace raw `db_connect()->table()`
     * у legacy notifyOldOwner pattern.
     *
     * Returns null якщо:
     *   - character не знайдено
     *   - character.telegram_user_id порожній
     *   - telegram_users row не знайдено
     */
    public function lookupOwnerTelegramId(int $charId): ?int
    {
        $charRow = $this->characterModel->find($charId);
        if (!$charRow) {
            return null;
        }
        $tgUserId = (int) ($charRow['telegram_user_id'] ?? 0);
        if (!$tgUserId) {
            return null;
        }

        $tgUserRow = $this->telegramUserModel->find($tgUserId);
        if (!$tgUserRow) {
            return null;
        }

        $tgId = (int) ($tgUserRow['telegram_id'] ?? 0);
        return $tgId > 0 ? $tgId : null;
    }
}
