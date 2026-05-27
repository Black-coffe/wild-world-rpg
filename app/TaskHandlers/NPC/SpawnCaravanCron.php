<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\CaravanModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\Player\CaravanService;
use App\Services\Player\DroneService;

/**
 * V25 (ADR-057) — спавн странствующих NPC-караванов. Запускается every minute
 * (Tasks.php singleInstance). Если active < max_active → spawn 1 каравана.
 *
 * Алгоритм:
 *   1. enabled? → no-op если killswitch off.
 *   2. countActive(status=active AND expires_at>NOW()). Если ≥ max_active → no-op.
 *   3. W5 (ADR-064): roll per-type drone.<type>.caravan_offer_chance (scout/cargo/
 *      repair/combat). Первый matching → drone-offer INSERT (offer_type='drone_<type>',
 *      gold_price = recipe.gold × markup). Иначе fallback на resource.
 *   4. Выбираем случайный редкий ресурс (rarity ≥ 7) с price > 0.
 *   5. Выбираем случайную клетку (Y in 401-900 — населённые ярусы, где игроки).
 *   6. INSERT caravans (resource или drone, см. шаг 3).
 *
 * Без Telegram-уведомлений (игрок узнаёт когда сам подходит на клетку).
 *
 * mt_rand используется ВНЕ simulateFight (cron context) → не задевает PvP fixture-fence.
 */
class SpawnCaravanCron
{
    private CaravanModel $caravanModel;
    private MapModel $mapModel;
    private ResourceModel $resourceModel;
    private CaravanService $service;
    private DroneService $droneService;

    public function __construct(
        ?CaravanModel $caravanModel = null,
        ?MapModel $mapModel = null,
        ?ResourceModel $resourceModel = null,
        ?CaravanService $service = null,
        ?DroneService $droneService = null
    ) {
        $this->caravanModel  = $caravanModel  ?? new CaravanModel();
        $this->mapModel      = $mapModel      ?? new MapModel();
        $this->resourceModel = $resourceModel ?? new ResourceModel();
        $this->service       = $service       ?? new CaravanService();
        $this->droneService  = $droneService  ?? new DroneService();
    }

    public function run(): void
    {
        if (! $this->service->enabled()) {
            return;
        }

        $active = $this->caravanModel->countActive();
        if ($active >= $this->service->maxActive()) {
            return;
        }

        $cellNumber = $this->pickRandomCell();
        if ($cellNumber <= 0) {
            return;
        }

        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + $this->service->lifetimeMinutes() * 60);

        // W5 (ADR-064): roll drone-offer chance per type. Первый matching выигрывает.
        // Если ни один не сработал → fallback на resource (V25 поведение).
        $droneOffer = $this->rollDroneOffer();
        if ($droneOffer !== null) {
            $this->caravanModel->insert([
                'cell_number'    => $cellNumber,
                'resource_id'    => null,
                'quantity'       => null,
                'price_per_unit' => null,
                'spawned_at'     => $now,
                'expires_at'     => $expires,
                'status'         => 'active',
                'offer_type'     => 'drone_' . $droneOffer['type'],
                'drone_type'     => $droneOffer['drone_name_eng'],
                'gold_price'     => $droneOffer['gold_price'],
            ]);
            return;
        }

        // Resource fallback (V25 unchanged).
        $resource = $this->pickRandomRareResource();
        if ($resource === null) {
            return;
        }
        $rawId    = $resource['id']    ?? null;
        $rawPrice = $resource['price'] ?? null;
        $resourceId = is_numeric($rawId)    ? (int) $rawId    : 0;
        $price      = is_numeric($rawPrice) ? (int) $rawPrice : 0;
        if ($resourceId <= 0 || $price <= 0) {
            return;
        }

        $qtyMin   = $this->service->qtyMin();
        $qtyMax   = $this->service->qtyMax();
        $quantity = mt_rand($qtyMin, $qtyMax);

        $pricePerUnit = $this->service->computePricePerUnit($price);

        $this->caravanModel->insert([
            'cell_number'    => $cellNumber,
            'resource_id'    => $resourceId,
            'quantity'       => $quantity,
            'price_per_unit' => $pricePerUnit,
            'spawned_at'     => $now,
            'expires_at'     => $expires,
            'status'         => 'active',
            'offer_type'     => 'resource',
        ]);
    }

    /**
     * W5 (ADR-064): roll per-type drone.<type>.caravan_offer_chance (scout/cargo/
     * repair/combat). Возвращает первый matching {type, drone_name_eng, gold_price}
     * или null если ни один не сработал. mt_rand вне simulateFight (cron context).
     *
     * @return array{type:string,drone_name_eng:string,gold_price:int}|null
     */
    private function rollDroneOffer(): ?array
    {
        $catalog = $this->service->droneOfferCatalog();
        foreach ($catalog as $type => $meta) {
            $chance = $this->droneService->caravanOfferChanceFor($type);
            if ($chance <= 0.0) {
                continue;
            }
            // Резолюция 1 промилле: mt_rand(1..10000) vs chance×10000.
            $roll = mt_rand(1, 10000);
            if ($roll <= (int) round($chance * 10000)) {
                $gold = $this->service->computeDroneOfferGold($type);
                if ($gold <= 0) {
                    continue;
                }
                return [
                    'type'           => $type,
                    'drone_name_eng' => $meta['recipe'],
                    'gold_price'     => $gold,
                ];
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function pickRandomRareResource(): ?array
    {
        $rows = $this->resourceModel
            ->where('rarity >=', 7)
            ->where('price >', 0)
            ->where('is_tradeable', 1)
            ->findAll();
        if (empty($rows)) {
            return null;
        }
        $pick = $rows[array_rand($rows)];
        return $this->normalizeRow($pick);
    }

    private function pickRandomCell(): int
    {
        // Населённые ярусы 4-7 (Y 401-900) — где большинство игроков.
        $rows = $this->mapModel
            ->where('coordinate_y >=', 401)
            ->where('coordinate_y <=', 900)
            ->orderBy('RAND()')
            ->limit(1)
            ->findAll();
        if (empty($rows)) {
            return 0;
        }
        $first = $this->normalizeRow($rows[0]);
        if ($first === null) {
            return 0;
        }
        $cell = $first['cell_number'] ?? null;
        return is_numeric($cell) ? (int) $cell : 0;
    }

    /**
     * CI4 Model может вернуть Entity (ArrayAccess) или array. Нормализуем в
     * array<string,mixed> для последующего чтения через is_numeric($row[key]).
     *
     * @return array<string,mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (is_array($row)) {
            $out = [];
            foreach ($row as $k => $v) {
                $out[(string) $k] = $v;
            }
            return $out;
        }
        if ($row instanceof \CodeIgniter\Entity\Entity) {
            $raw = $row->toRawArray();
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(string) $k] = $v;
            }
            return $out;
        }
        return null;
    }
}
