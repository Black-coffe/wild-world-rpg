<?php

declare(strict_types=1);

namespace App\Services\Player\Death;

use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\EventModel;
use App\Models\MapModel;

/**
 * F2.8b — выбор клетки respawn'а после смерти и проверка наличия базы.
 *
 * Извлечено из DeathService::respawnPlayer + checkIfPlayerHasActiveBase
 * (legacy v0.5.0). Логика 1:1:
 *   - если есть claimed_cells.status='active' → респавн на map_cell_id базы
 *   - иначе случайная ячейка из map где Y in [900..999], biome_id ∈ [1,2,3,6]
 *   - fallback cell_number = 1 если совсем ничего не нашли
 *
 * Death-validation batch 4 («чистый респаун»): `respawn()` дополнительно вызывает
 * {@see cleanupAfterDeath()} — выставляет `characters.last_respawn_at = now()` (для grace-окна
 * иммунитета к damage-событиям, см. `GameBalance::$respawnGraceMinutes`) и снимает
 * персонажа с `effect_log` всех активных негативных (`damage`/`debuff`) событий — по лору
 * смерть «обнуляет» болезни/дебаффы (карточка `inbox/2026-05-11-validation-card-death-notifications.md`).
 * Это центральная точка: `DeathService::handlePlayerDeathAndReward()` всегда вызывает
 * `respawn()`, так что покрыты все ветки смерти (рулетка / PvP / NPC / общая).
 *
 * Models инжектируются для testability.
 */
class PlayerRespawner
{
    private CharacterModel   $characterModel;
    private ClaimedCellModel $claimedCellModel;
    private MapModel         $mapModel;
    private ActiveEventModel $activeEventModel;
    private EventModel       $eventModel;

    public function __construct(
        ?CharacterModel $characterModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?MapModel $mapModel = null,
        ?ActiveEventModel $activeEventModel = null,
        ?EventModel $eventModel = null
    ) {
        $this->characterModel   = $characterModel   ?? new CharacterModel();
        $this->claimedCellModel = $claimedCellModel ?? new ClaimedCellModel();
        $this->mapModel         = $mapModel         ?? new MapModel();
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->eventModel       = $eventModel       ?? new EventModel();
    }

    /**
     * Возвращает true если у персонажа есть активная база.
     */
    public function hasActiveBase(int $characterId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        return !empty($row);
    }

    /**
     * General death respawn — claimed.status='active' → biome whitelist Y∈[900-999] → fallback 1.
     *
     * Це 1 з 3 різних respawn implementations у repo:
     * - {@see \App\Services\PVE\PvpRewardOrchestrator::findRespawnCell} — PvP exhaustion (claimed → explored)
     * - {@see \App\TaskHandlers\DeathRouletteHandler::findRespawnCell} — death roulette (claimed → explored)
     * - {@see PlayerRespawner::respawn} (this) — general death (claimed → biome whitelist)
     *
     * Semantically intentional divergence — general death uses safe-biome fallback (Y=900-999,
     * biome ∈ [1,2,3,6]) для гарантованої безпечної locации, тоді як PvP/roulette use explored
     * cells (можуть бути dangerous biomes — це частина death penalty).
     *
     * Перенести персонажа на respawn-клетку + post-death cleanup (batch 4). Возвращает новый cell_number.
     */
    public function respawn(int $characterId): int
    {
        $claimed = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if ($claimed) {
            $respawnCell = (int) $claimed['map_cell_id'];
        } else {
            $cells = $this->mapModel
                ->where('coordinate_y >=', 900)
                ->where('coordinate_y <=', 999)
                ->whereIn('biome_id', [1, 2, 3, 6])
                ->findAll();
            if (!empty($cells)) {
                $randomCell  = $cells[array_rand($cells)];
                $respawnCell = (int) $randomCell['cell_number'];
            } else {
                $respawnCell = 1;
            }
        }

        $this->characterModel->update($characterId, ['cell_number' => $respawnCell]);
        $this->cleanupAfterDeath($characterId);

        return $respawnCell;
    }

    /**
     * Death-validation batch 4 — «чистый респаун»:
     *   1) `characters.last_respawn_at = now()` (для grace-окна иммунитета к damage-событиям);
     *   2) снять персонажа с `effect_log` всех активных негативных (`damage`/`debuff`) событий —
     *      смерть «обнуляет» болезни/дебаффы. Убираем ТОЛЬКО запись умершего — end-summary
     *      других игроков того же события не ломаем.
     */
    public function cleanupAfterDeath(int $characterId): void
    {
        $this->characterModel->update($characterId, ['last_respawn_at' => date('Y-m-d H:i:s')]);

        $negativeEventIds = $this->eventModel->whereIn('effect_type', ['damage', 'debuff'])->findColumn('event_id');
        if (empty($negativeEventIds)) {
            return;
        }

        $key  = (string) $characterId;
        $rows = $this->activeEventModel
            ->where('status', 'active')
            ->whereIn('event_id', $negativeEventIds)
            ->findAll();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $row['effect_log'] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $log = json_decode($raw, true);
            if (!is_array($log) || !array_key_exists($key, $log)) {
                continue;
            }
            unset($log[$key]);
            $rowId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($rowId > 0) {
                $this->activeEventModel->update($rowId, ['effect_log' => json_encode($log)]);
            }
        }
    }
}
