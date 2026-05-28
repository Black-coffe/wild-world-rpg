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
        'caravan_group_id', // W14a (ADR-068) — multi-resource bundle: общий id строк одного богатого каравана; NULL = одиночный offer
        'faction_id',       // W15 (ADR-069) — faction-affinity каравана (1-4); NULL = нейтральный
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

    /**
     * W14a (ADR-068) — сколько ещё активных товаров (qty>0) осталось в богатом
     * караване (группе). Для корректного сообщения после покупки одного товара
     * («у каравана ещё есть товары» vs «распродал всё»). Raw db-builder.
     */
    public function countActiveInGroup(int $groupId): int
    {
        if ($groupId <= 0) {
            return 0;
        }
        $count = $this->db->table('caravans')
            ->where('caravan_group_id', $groupId)
            ->where('status', 'active')
            ->where('quantity >', 0)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->countAllResults();
        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * W14a (ADR-068) — следующий свободный caravan_group_id для нового богатого
     * каравана (MAX+1). Raw db-builder — без model-builder-state quirk.
     */
    public function nextGroupId(): int
    {
        $row = $this->db->table('caravans')
            ->selectMax('caravan_group_id', 'max_gid')
            ->get();
        $arr = $row === false ? null : $row->getRowArray();
        $max = is_array($arr) && is_numeric($arr['max_gid'] ?? null) ? (int) $arr['max_gid'] : 0;
        return $max + 1;
    }
}
