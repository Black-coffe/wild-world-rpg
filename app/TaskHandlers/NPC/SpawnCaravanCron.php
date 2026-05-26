<?php

declare(strict_types=1);

namespace App\TaskHandlers\NPC;

use App\Models\CaravanModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\Player\CaravanService;

/**
 * V25 (ADR-057) — спавн странствующих NPC-караванов. Запускается every minute
 * (Tasks.php singleInstance). Если active < max_active → spawn 1 каравана.
 *
 * Алгоритм:
 *   1. enabled? → no-op если killswitch off.
 *   2. countActive(status=active AND expires_at>NOW()). Если ≥ max_active → no-op.
 *   3. Выбираем случайный редкий ресурс (rarity ≥ 7) с price > 0.
 *   4. Выбираем случайную клетку (Y in 401-900 — населённые ярусы, где игроки).
 *   5. INSERT caravans с lifetime_minutes, qty в [qty_min, qty_max], price со скидкой.
 *
 * Без Telegram-уведомлений (игрок узнаёт когда сам подходит на клетку).
 */
class SpawnCaravanCron
{
    private CaravanModel $caravanModel;
    private MapModel $mapModel;
    private ResourceModel $resourceModel;
    private CaravanService $service;

    public function __construct(
        ?CaravanModel $caravanModel = null,
        ?MapModel $mapModel = null,
        ?ResourceModel $resourceModel = null,
        ?CaravanService $service = null
    ) {
        $this->caravanModel  = $caravanModel  ?? new CaravanModel();
        $this->mapModel      = $mapModel      ?? new MapModel();
        $this->resourceModel = $resourceModel ?? new ResourceModel();
        $this->service       = $service       ?? new CaravanService();
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

        $cellNumber = $this->pickRandomCell();
        if ($cellNumber <= 0) {
            return;
        }

        $qtyMin  = $this->service->qtyMin();
        $qtyMax  = $this->service->qtyMax();
        $quantity = mt_rand($qtyMin, $qtyMax);

        $pricePerUnit = $this->service->computePricePerUnit($price);

        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + $this->service->lifetimeMinutes() * 60);

        $this->caravanModel->insert([
            'cell_number'    => $cellNumber,
            'resource_id'    => $resourceId,
            'quantity'       => $quantity,
            'price_per_unit' => $pricePerUnit,
            'spawned_at'     => $now,
            'expires_at'     => $expires,
            'status'         => 'active',
        ]);
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
