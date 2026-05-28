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

        // W14a (ADR-068): roll богатый караван (multi-resource bundle). Hit → bundle и return;
        // miss (или пул < 2 ресурсов) → одиночный resource-offer (V25 поведение ниже).
        if ($this->shouldBundle() && $this->spawnBundle($cellNumber, $now, $expires)) {
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
            'faction_id'     => $this->rollFactionId(), // W15 (ADR-069)
        ]);
    }

    /**
     * W15 (ADR-069) — faction-affinity каравана: при factionAffinityEnabled + roll
     * affinity_chance → random фракция 1-4; иначе null (нейтральный). mt_rand вне
     * simulateFight (cron context) → RNG-fence safe.
     */
    private function rollFactionId(): ?int
    {
        if (! $this->service->factionAffinityEnabled()) {
            return null;
        }
        $chance = $this->service->factionAffinityChance();
        if ($chance <= 0.0 || mt_rand(1, 10000) > (int) round($chance * 10000)) {
            return null;
        }
        return mt_rand(1, 4);
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
        $pool = $this->rareResourcePool();
        if ($pool === []) {
            return null;
        }
        return $pool[array_rand($pool)];
    }

    /**
     * W14a (ADR-068) — пул редких торгуемых ресурсов (rarity≥7, price>0, tradeable),
     * нормализованных в array. Общий для одиночного offer'а и bundle.
     *
     * @return list<array<string,mixed>>
     */
    private function rareResourcePool(): array
    {
        $rows = $this->resourceModel
            ->where('rarity >=', 7)
            ->where('price >', 0)
            ->where('is_tradeable', 1)
            ->findAll();
        $out = [];
        foreach ($rows as $row) {
            $norm = $this->normalizeRow($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /** W14a — сработал ли roll богатого каравана (детерминированный promille-roll, как drone). */
    private function shouldBundle(): bool
    {
        $chance = $this->service->bundleChance();
        if ($chance <= 0.0) {
            return false;
        }
        return mt_rand(1, 10000) <= (int) round($chance * 10000);
    }

    /**
     * W14a (ADR-068) — спавнит богатый караван: N=rand(bundle_min..bundle_max) РАЗНЫХ
     * редких ресурсов с общим caravan_group_id+cell+expires. Возвращает false, если
     * пул < 2 (нет смысла в bundle → caller fallback'нет на одиночный offer).
     */
    private function spawnBundle(int $cellNumber, string $now, string $expires): bool
    {
        $pool = $this->rareResourcePool();
        if (count($pool) < 2) {
            return false;
        }
        shuffle($pool);

        $n = mt_rand($this->service->bundleMin(), $this->service->bundleMax());
        $n = max(2, min($n, count($pool)));

        $groupId   = $this->caravanModel->nextGroupId();
        $factionId = $this->rollFactionId(); // W15: один faction_id на весь bundle
        $inserted  = 0;
        for ($i = 0; $i < $n; $i++) {
            $resource   = $pool[$i];
            $rawId      = $resource['id']    ?? null;
            $rawPrice   = $resource['price'] ?? null;
            $resourceId = is_numeric($rawId)    ? (int) $rawId    : 0;
            $price      = is_numeric($rawPrice) ? (int) $rawPrice : 0;
            if ($resourceId <= 0 || $price <= 0) {
                continue;
            }
            $this->caravanModel->insert([
                'cell_number'      => $cellNumber,
                'caravan_group_id' => $groupId,
                'faction_id'       => $factionId, // W15: общий для bundle
                'resource_id'      => $resourceId,
                'quantity'         => mt_rand($this->service->qtyMin(), $this->service->qtyMax()),
                'price_per_unit'   => $this->service->computePricePerUnit($price),
                'spawned_at'       => $now,
                'expires_at'       => $expires,
                'status'           => 'active',
                'offer_type'       => 'resource',
            ]);
            $inserted++;
        }
        return $inserted >= 2;
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
