<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-101 — ростер жителей поселения (settlement_npcs). Контент-определение (KEEP в вайпе).
 *
 * role: mayor|vendor|guard|quest|service. service_key: trade|repair|casino|recycle|faction_project.
 */
class SettlementNpcModel extends Model
{
    protected $table         = 'settlement_npcs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'settlement_id', 'npc_id', 'role', 'service_key', 'sort_order',
    ];

    /**
     * Жители поселения (по sort_order).
     *
     * @return list<array<int|string,mixed>>
     */
    public function forSettlement(int $settlementId): array
    {
        $out = [];
        foreach ($this->where('settlement_id', $settlementId)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
