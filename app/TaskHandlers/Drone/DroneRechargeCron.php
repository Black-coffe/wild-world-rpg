<?php

declare(strict_types=1);

namespace App\TaskHandlers\Drone;

use App\Models\CharacterModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\DroneService;

/**
 * W2 (ADR-058) + W3b (ADR-060) + W4 (ADR-063) + W5 (ADR-064) + ADR-174/transport-17 —
 * recharge cron для всех типов дронов (scout + cargo + repair + combat + транспортный
 * AutonomousDrone). Запускается every minute (Tasks.php singleInstance).
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
            [
                'name_eng' => 'DroneRepair',
                'enabled'  => $this->service->repairIsEnabled(),
                'max'      => $this->service->repairBatteryMax(),
                'rate'     => $this->service->repairChargeRatePerMinute(),
            ],
            [
                'name_eng' => 'DroneCombat',
                'enabled'  => $this->service->combatIsEnabled(),
                'max'      => $this->service->combatBatteryMax(),
                'rate'     => $this->service->combatChargeRatePerMinute(),
            ],
            [
                // ADR-174 (story transport-17) — «зависимость от энергии»
                // Инженеров: транспортный дрон заряжается на базе, а не чинится
                // как остальные машины. Пятый тип, тот же контракт.
                'name_eng' => 'AutonomousDrone',
                'enabled'  => $this->service->droneAutoIsEnabled(),
                'max'      => $this->service->droneAutoBatteryMax(),
                'rate'     => $this->service->droneAutoChargeRatePerMinute(),
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

        // Доля скорости вне базы (просьба игрока 18.08.2026: «заряжаться везде, на базе
        // быстро, в поле медленнее»). 0 = прежнее поведение, в поле заряда нет вовсе.
        $fieldFactor = $this->service->fieldChargeFactor();

        foreach ($logsByChar as $charId => $logs) {
            $onBase = $this->isCharacterOnBase($charId);
            if (! $onBase && $fieldFactor <= 0.0) {
                continue;
            }
            foreach ($logs as $log) {
                $logId   = $this->extractInt($log, 'id');
                $current = $this->extractInt($log, 'durability_count');
                if ($logId <= 0) {
                    continue;
                }
                // Шаг заряда за тик. round() мог бы дать 0 при rate<0.5
                // (base_charge_minutes_per_full>200) → дрон не заряжался бы НИКОГДА.
                // Floor=1 страхует: дрон всегда прогрессирует (rate>0 гарантирован
                // выше через `$cfg['rate'] <= 0.0 → continue`).
                $step = (int) round($rate * $intervalMinutes);
                if ($step < 1) {
                    $step = 1;
                }

                if (! $onBase) {
                    // 🔴 В поле floor=1 применять НЕЛЬЗЯ: при доле 25% и минутном тике
                    // округление вверх сделало бы полевую зарядку такой же быстрой, как
                    // базовую. Поэтому считаем от времени последнего изменения строки и
                    // ждём, пока накопится хотя бы единица (`updated_at` обновится этим же
                    // UPDATE — счётчик самосбрасывается, отдельная колонка не нужна).
                    $minutes = $this->minutesSinceUpdate($log, $intervalMinutes);
                    $step    = (int) floor($minutes * $rate * $fieldFactor);
                    if ($step < 1) {
                        continue;
                    }
                }
                $next = (int) min($batteryMax, $current + $step);
                if ($next === $current) {
                    continue;
                }
                $this->logModel->update($logId, ['durability_count' => $next]);
            }
        }
    }

    /**
     * Сколько минут прошло с последнего изменения строки дрона. Нужен для полевой
     * зарядки: она медленнее минутного тика, и шаг приходится копить по времени.
     *
     * @param array<array-key, mixed>|object $log
     */
    private function minutesSinceUpdate(array|object $log, int $fallbackMinutes): float
    {
        $raw = is_array($log) ? ($log['updated_at'] ?? null) : ($log->updated_at ?? null);
        if (! is_string($raw) || $raw === '') {
            return (float) $fallbackMinutes;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return (float) $fallbackMinutes;
        }

        return max(0.0, (time() - $ts) / 60);
    }

    /**
     * Игрок физически стоит на СВОЕЙ активной claimed-клетке (базе).
     *
     * 🔴 Фикс BUILT-BUT-DEAD (2026-06-22): раньше читали несуществующую колонку
     * `claimed_cells.cell_number` (в таблице только `map_cell_id`) → результат
     * всегда false → scout-дроны НЕ заряжались НИКОГДА с W2. Канонический способ
     * (ADR-095): `claimed_cells.map_cell_id == characters.cell_number` (в `map`
     * id == cell_number), status='active' — через `findActiveCell`. Multi-base-safe.
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

        return $this->claimedCellModel->findActiveCell($characterId, $charCell) !== null;
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
