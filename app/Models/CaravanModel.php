<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * V25 (ADR-057) — модель таблицы `caravans` (странствующие NPC-торговцы).
 */
class CaravanModel extends Model
{
    protected $table         = 'caravans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'cell_number',
        'resource_id',
        'quantity',
        'price_per_unit',
        'spawned_at',
        'expires_at',
        'status',
        // W5 (ADR-064) — drone-offer extension
        'offer_type',  // 'resource' (default V25) / 'drone_scout' / 'drone_cargo' / 'drone_repair' / 'drone_combat'
        'drone_type',  // name_eng DroneScout/DroneCargo/... NULL для resource-offer'ов
        'gold_price',  // recipe.gold × markup; NULL для resource-offer'ов
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function findActiveOnCell(int $cellNumber): array
    {
        $rows = $this->where('cell_number', $cellNumber)
            ->where('status', 'active')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->findAll();
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = [];
            foreach ($row as $k => $v) {
                $normalized[(string) $k] = $v;
            }
            $out[] = $normalized;
        }
        return $out;
    }

    public function countActive(): int
    {
        $count = $this->where('status', 'active')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->countAllResults();
        return is_numeric($count) ? (int) $count : 0;
    }
}
