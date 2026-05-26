<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * W3a (ADR-059) — Cargo drone delivery target. Ресурсы, доставленные дроном
 * (или future-механиками) на базу. Чистая сепарация carried (`character_resources`)
 * vs base (`base_storage`) — резолюция Q2 ADR-059.
 *
 * Retrieve flow (резолюция Q5): игрок на базе → action «Склад» → selective
 * per-resource «Забрать X kg → карман» + комбо-кнопка «Забрать всё».
 *
 * `arrived_from_cell` — origin клетки (cargo-log / arrival-history для лор-привязки).
 */
class BaseStorageModel extends Model
{
    protected $table         = 'base_storage';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';

    protected $allowedFields = [
        'character_id',
        'resource_id',
        'quantity',
        'arrived_from_cell',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Все entries персонажа (для retrieve UI «Склад»). Modelreturn`array` (см.
     * `$returnType = 'array'`), но CI4 generic-сигнатура шире — приводим к нашей.
     *
     * @return list<array<int|string, mixed>>
     */
    public function findByCharacter(int $characterId): array
    {
        $rows = $this->where('character_id', $characterId)
            ->orderBy('updated_at', 'DESC')
            ->findAll();
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Найти entry по character + resource (для merge при доставке).
     *
     * @return array<int|string, mixed>|null
     */
    public function findEntry(int $characterId, int $resourceId): ?array
    {
        $row = $this->where('character_id', $characterId)
            ->where('resource_id', $resourceId)
            ->first();
        return is_array($row) ? $row : null;
    }

    /**
     * Доставка партии (idempotent merge): если row уже есть — quantity +=;
     * иначе insert новый. Возвращает id row'а.
     */
    public function deliver(int $characterId, int $resourceId, int $quantity, ?int $fromCell = null): int
    {
        if ($quantity < 1) {
            return 0;
        }
        $existing = $this->findEntry($characterId, $resourceId);
        if ($existing !== null) {
            $rawQty   = $existing['quantity'] ?? 0;
            $rawId    = $existing['id'] ?? 0;
            $curQty   = is_numeric($rawQty) ? (int) $rawQty : 0;
            $entryId  = is_numeric($rawId) ? (int) $rawId : 0;
            if ($entryId > 0) {
                $this->update($entryId, ['quantity' => $curQty + $quantity]);
                return $entryId;
            }
        }
        $newId = $this->insert([
            'character_id'      => $characterId,
            'resource_id'       => $resourceId,
            'quantity'          => $quantity,
            'arrived_from_cell' => $fromCell,
        ]);
        return is_numeric($newId) ? (int) $newId : 0;
    }
}
