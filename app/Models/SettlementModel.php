<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-101 — статические NPC-поселения (Rust-style). Мировой контент (KEEP в вайпе).
 *
 * type: outpost|bandit|faction|ruins. zone_policy: safe|hostile|neutral. enabled=0 → dormant.
 */
class SettlementModel extends Model
{
    protected $table         = 'settlements';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'code', 'name_ru', 'type', 'anchor_cell', 'coordinate_x', 'coordinate_y',
        'faction_id', 'zone_policy', 'enter_min_standing', 'icon', 'description_ru', 'enabled',
    ];

    /**
     * Активное поселение на якорной клетке (enabled=1). null если нет.
     *
     * @return array<int|string,mixed>|null
     */
    public function activeByAnchorCell(int $cellNumber): ?array
    {
        $row = $this->where('anchor_cell', $cellNumber)->where('enabled', 1)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Все активные поселения (enabled=1).
     *
     * @return list<array<int|string,mixed>>
     */
    public function allActive(): array
    {
        $out = [];
        foreach ($this->where('enabled', 1)->findAll() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
