<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Services\Bases\BaseCheckService;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * W4 (ADR-063) — общая логика batch-ремонта (preview + commit).
 *
 * Зеркало RobotRepairBaseAction (V19): подклассы рендерят превью /
 * выполняют commit. Здесь — резолв drone-инстанса + список ремонтопригодных
 * роботов + cumulative gold-cost через DroneRepair markup formula.
 *
 * Distinct-value vs V19:
 *  - V19: gold + resources, per-robot ручной, мгновенный (RobotRepairService::computeCost).
 *  - W4 DroneRepair: gold-only batch (все роботы чара одним кликом за 1 battery drain),
 *    requires crafted_item + charge + on-base. Конвертация resources→gold через
 *    `Σ(qty × resources.price × cost_fraction × dur_frac × markup)`.
 */
abstract class RepairDroneBaseAction extends BaseAction
{
    protected CraftedItemsModel    $itemsModel;
    protected CraftedItemsLogModel $logModel;
    /** @var ResourceModel — переопределён без type из-за parent property без типа. */
    protected $resourceModel;
    protected DroneService         $service;
    protected BaseCheckService     $baseCheck;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->itemsModel    = new CraftedItemsModel();
        $this->logModel      = new CraftedItemsLogModel();
        $this->resourceModel = new ResourceModel();
        $this->service       = new DroneService();
        $this->baseCheck     = new BaseCheckService();
    }

    /**
     * Собирает контекст batch-ремонта.
     *
     * @return array{
     *     ok:bool,
     *     message:string,
     *     droneLog:array<array-key,mixed>,
     *     droneCharge:int,
     *     droneBatteryMax:int,
     *     droneDrain:int,
     *     robots:list<array{logId:int,nameRus:string,current:int,base:int,goldCost:int}>,
     *     cumulativeGold:int
     * }
     */
    protected function buildContext(int $characterId): array
    {
        $fail = static function (string $message): array {
            return [
                'ok' => false, 'message' => $message,
                'droneLog' => [], 'droneCharge' => 0, 'droneBatteryMax' => 0, 'droneDrain' => 0,
                'robots' => [], 'cumulativeGold' => 0,
            ];
        };

        if (! $this->service->repairIsEnabled()) {
            return $fail('Дрон-ремонтник временно недоступен.');
        }

        if (! $this->baseCheck->checkBaseStatus($characterId)['hasBase']) {
            return $fail('У тебя нет базы — запускать дрон-ремонтник негде.');
        }

        $droneItem = $this->itemsModel->where('name_eng', 'DroneRepair')->first();
        if (! is_array($droneItem)) {
            return $fail('Дрон-ремонтник не найден в каталоге крафта.');
        }
        $droneItemId = is_numeric($droneItem['id'] ?? null) ? (int) $droneItem['id'] : 0;
        if ($droneItemId <= 0) {
            return $fail('Дрон-ремонтник не найден в каталоге крафта.');
        }

        $droneLog = $this->logModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $droneItemId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();
        if (! is_array($droneLog)) {
            return $fail('У тебя нет скрафченного дрон-ремонтника. Скрафти его в Стандартном верстаке (нужен RoboticsWorkshop L3).');
        }

        $batteryMax = $this->service->repairBatteryMax();
        $drain      = $this->service->repairBatteryDrainPerLaunch();
        $charge     = is_numeric($droneLog['durability_count'] ?? null) ? (int) $droneLog['durability_count'] : 0;

        $robots         = $this->collectEligibleRobots($characterId);
        $cumulativeGold = 0;
        foreach ($robots as $r) {
            $cumulativeGold += $r['goldCost'];
        }
        if (! empty($robots) && $cumulativeGold < $this->service->repairMinCostGold()) {
            $cumulativeGold = $this->service->repairMinCostGold();
        }

        return [
            'ok'             => true,
            'message'        => '',
            'droneLog'       => $droneLog,
            'droneCharge'    => $charge,
            'droneBatteryMax' => $batteryMax,
            'droneDrain'     => $drain,
            'robots'         => $robots,
            'cumulativeGold' => $cumulativeGold,
        ];
    }

    /**
     * Собирает всех ремонтопригодных роботов чара (type='robots', quantity>0,
     * durability_count<base_durability). Вычисляет gold-cost per-robot.
     *
     * @return list<array{logId:int,nameRus:string,current:int,base:int,goldCost:int}>
     */
    protected function collectEligibleRobots(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT l.id AS log_id, l.durability_count AS current,
                    i.id AS item_id, i.name_eng, i.name_rus, i.durability_count AS base
             FROM crafted_items_log l
             INNER JOIN crafted_items i ON i.id = l.crafted_item_id
             WHERE l.character_id = ?
               AND l.quantity > 0
               AND i.type = ?
               AND l.durability_count < i.durability_count',
            [$characterId, 'robots']
        );
        $rows = is_object($q) && method_exists($q, 'getResultArray') ? $q->getResultArray() : [];

        $costFrac = $this->service->repairCostFraction();
        $markup   = $this->service->repairMarkup();

        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $logId   = is_numeric($r['log_id'] ?? null) ? (int) $r['log_id'] : 0;
            $current = is_numeric($r['current'] ?? null) ? (int) $r['current'] : 0;
            $base    = is_numeric($r['base'] ?? null) ? (int) $r['base'] : 0;
            $nameEn  = is_string($r['name_eng'] ?? null) ? $r['name_eng'] : '';
            $nameRus = is_string($r['name_rus'] ?? null) ? $r['name_rus'] : $nameEn;
            if ($logId <= 0 || $base <= 0 || $current >= $base) {
                continue;
            }

            // По name_eng, а не по ключу рецепта — они совпадают не всегда (S5b-баг ремонта).
            $recipe = config(\Config\CraftRecipes::class)->findByItemNameEng($nameEn);
            if (! is_array($recipe)) {
                continue;
            }

            $goldCost = $this->computeRobotGoldCost($recipe, $current, $base, $costFrac, $markup);
            $out[] = [
                'logId'    => $logId,
                'nameRus'  => $nameRus,
                'current'  => $current,
                'base'     => $base,
                'goldCost' => $goldCost,
            ];
        }
        return $out;
    }

    /**
     * Gold-cost ремонта одного робота через DroneRepair:
     *   gold = ceil( Σ (qty[res] × resources.price[res]) × cost_fraction × dur_frac × markup )
     *
     * @param array<array-key,mixed> $recipe
     */
    protected function computeRobotGoldCost(array $recipe, int $current, int $base, float $costFrac, float $markup): int
    {
        if ($base <= 0 || $current >= $base) {
            return 0;
        }
        $durFrac = ($base - $current) / $base;

        $template = $recipe['resources'] ?? [];
        if (! is_array($template)) {
            return 0;
        }

        $sumRubles = 0.0;
        foreach ($template as $name => $qtyRaw) {
            if (! is_string($name) || $name === '' || ! is_numeric($qtyRaw)) {
                continue;
            }
            $price = $this->loadResourcePrice($name);
            if ($price <= 0) {
                continue;
            }
            $sumRubles += ((float) $qtyRaw) * (float) $price;
        }

        return (int) ceil($sumRubles * $costFrac * $durFrac * $markup);
    }

    /** SELECT resources.price WHERE name=$name. Cache внутри одного запроса не нужен (мало вызовов). */
    protected function loadResourcePrice(string $name): int
    {
        $row = $this->resourceModel->where('name', $name)->first();
        if (is_object($row)) {
            $row = $row->toArray();
        }
        if (is_array($row) && isset($row['price']) && is_numeric($row['price'])) {
            return (int) $row['price'];
        }
        return 0;
    }

    /** Charge-bar 10-сегментный. */
    protected function renderChargeBar(int $pct): string
    {
        $pct = max(0, min(100, $pct));
        $filled = (int) round($pct / 10);
        $empty  = 10 - $filled;
        return str_repeat('▰', $filled) . str_repeat('▱', $empty);
    }
}
