<?php

declare(strict_types=1);

namespace App\TaskHandlers\Drone;

use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\DroneService;

/**
 * W2 (ADR-058) + W3b (ADR-060) — recharge cron для всех типов дронов
 * (scout + cargo). Запускается every minute (Tasks.php singleInstance).
 * Для каждого характера, чьи дроны не на полном заряде И сам персонаж
 * сейчас на своей базе — увеличиваем durability_count у его drone-log-row
 * на (rate × interval_minutes), clamp battery_max.
 *
 * W3b: generalize через TYPES array с per-type battery_max + rate.
 * Один cron'тик обходит все типы дронов в одном scan'е.
 *
 * Алгоритм (per-type):
 *   1. enabled (cargoIsEnabled или isEnabled) → no-op.
 *   2. Резолвим crafted_items.id по name_eng (DroneScout / DroneCargo).
 *   3. SELECT log rows: WHERE crafted_item_id=X AND quantity>0 AND durability_count<max.
 *   4. Группируем по character_id: для каждого чара 1 раз isOnBase-check.
 *   5. На базе → UPDATE durability_count += charge_per_minute (clamp max). Иначе пропуск.
 *
 * Lazy fallback: если cron пропустил тики (downtime), при следующем тике charge
 * увеличивается на charge_per_minute × 1 (минимум). Не идеально, но MVP.
 */
class DroneRechargeCron
{
    private DroneService $service;
    private CraftedItemsModel $itemModel;
    private CraftedItemsLogModel $logModel;
    private CharacterModel $charModel;
    private ClaimedCellModel $claimedCellModel;

    public function __construct(
        ?DroneService $service = null,
        ?CraftedItemsModel $itemModel = null,
        ?CraftedItemsLogModel $logModel = null,
        ?CharacterModel $charModel = null,
        ?ClaimedCellModel $claimedCellModel = null
    ) {
        $this->service          = $service          ?? new DroneService();
        $this->itemModel        = $itemModel        ?? new CraftedItemsModel();
        $this->logModel         = $logModel         ?? new CraftedItemsLogModel();
        $this->charModel        = $charModel        ?? new CharacterModel();
        $this->claimedCellModel = $claimedCellModel ?? new ClaimedCellModel();
    }

    /**
     * Cron entry. Interval по умолчанию = 1 минута (см. Tasks.php).
     * Передаваемый параметр $intervalMinutes — для тестов / адаптации частоты.
     */
    public function run(int $intervalMinutes = 1): void
    {
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        }

        $types = [
            [
                'name_eng' => 'DroneScout',
                'enabled'  => $this->service->isEnabled(),
                'max'      => $this->service->batteryMax(),
                'rate'     => $this->service->chargeRatePerMinute(),
            ],
            [
                'name_eng' => 'DroneCargo',
                'enabled'  => $this->service->cargoIsEnabled(),
                'max'      => $this->service->cargoBatteryMax(),
                'rate'     => $this->service->cargoChargeRatePerMinute(),
            ],
        ];

        foreach ($types as $cfg) {
            if (! $cfg['enabled']) {
                continue;
            }
            if ($cfg['rate'] <= 0.0) {
                continue;
            }
            $this->rechargeType($cfg['name_eng'], (int) $cfg['max'], (float) $cfg['rate'], $intervalMinutes);
        }
    }

    private function rechargeType(string $nameEng, int $batteryMax, float $rate, int $intervalMinutes): void
    {
        $droneRow = $this->itemModel->where('name_eng', $nameEng)->first();
        if (! is_array($droneRow)) {
            return;
        }
        $rawDroneId  = $droneRow['id'] ?? null;
        $droneItemId = is_numeric($rawDroneId) ? (int) $rawDroneId : 0;
        if ($droneItemId <= 0) {
            return;
        }

        $logRows = $this->logModel
            ->where('crafted_item_id', $droneItemId)
            ->where('quantity >', 0)
            ->where('durability_count <', $batteryMax)
            ->findAll();
        if (empty($logRows)) {
            return;
        }

        $logsByChar = [];
        foreach ($logRows as $log) {
            $charId = $this->extractInt($log, 'character_id');
            if ($charId <= 0) {
                continue;
            }
            $logsByChar[$charId][] = $log;
        }

        foreach ($logsByChar as $charId => $logs) {
            if (! $this->isCharacterOnBase($charId)) {
                continue;
            }
            foreach ($logs as $log) {
                $logId   = $this->extractInt($log, 'id');
                $current = $this->extractInt($log, 'durability_count');
                if ($logId <= 0) {
                    continue;
                }
                $next = (int) min($batteryMax, $current + (int) round($rate * $intervalMinutes));
                if ($next === $current) {
                    continue;
                }
                $this->logModel->update($logId, ['durability_count' => $next]);
            }
        }
    }

    /**
     * Игрок физически стоит на claimed-клетке своей базы.
     */
    private function isCharacterOnBase(int $characterId): bool
    {
        $char = $this->charModel->find($characterId);
        if (! is_array($char) && ! is_object($char)) {
            return false;
        }
        $charCell = $this->extractInt($char, 'cell_number');
        if ($charCell <= 0) {
            return false;
        }

        $claim = $this->claimedCellModel->where('character_id', $characterId)->first();
        if (! is_array($claim) && ! is_object($claim)) {
            return false;
        }
        $claimCell = $this->extractInt($claim, 'cell_number');
        return $claimCell > 0 && $claimCell === $charCell;
    }

    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }
}
