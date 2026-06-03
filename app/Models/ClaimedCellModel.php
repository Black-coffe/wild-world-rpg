<?php

namespace App\Models;

use CodeIgniter\Model;

class ClaimedCellModel extends Model
{
    protected $table = 'claimed_cells';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'character_id',
        'map_cell_id',
        'claimed_at',
        'last_visited_at',
        'status',
        'camp_name',
        'camp_flag',
        'camp_hearth',
        'camp_furniture',
        'camp_pet',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'claimed_at';
    protected $updatedField  = '';
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'character_id' => 'required|integer',
        'map_cell_id' => 'required|integer',
        'claimed_at' => 'required|valid_date',
        'status' => 'required|in_list[active,abandoned]'
    ];

    protected $validationMessages = [
        'character_id' => [
            'required' => 'Character ID is required.',
            'integer' => 'Character ID must be an integer.'
        ],
        'map_cell_id' => [
            'required' => 'Map cell ID is required.',
            'integer' => 'Map cell ID must be an integer.'
        ],
        'claimed_at' => [
            'required' => 'Claimed at date is required.',
            'valid_date' => 'Claimed at must be a valid date.'
        ],
        'status' => [
            'required' => 'Status is required.',
            'in_list' => 'Status must be either "active" or "abandoned".'
        ]
    ];

    protected $skipValidation = false;

    /**
     * ADR-095 Фаза 1b — «активная база»: claimed_cell, на которой персонаж стоит
     * СЕЙЧАС (map_cell_id == текущий cell_number, status='active'). null — игрок не
     * на своей базе. В таблице map id == cell_number для всех клеток, поэтому
     * map_cell_id (= map.id) корректно сравнивается с character.cell_number.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveCell(int $characterId, int $cellNumber): ?array
    {
        if ($cellNumber <= 0) {
            return null;
        }
        $row = $this->where('character_id', $characterId)
            ->where('map_cell_id', $cellNumber)
            ->where('status', 'active')
            ->first();
        if (! is_array($row)) {
            return null;
        }
        return $this->normalizeKeys($row);
    }

    /**
     * ADR-095 Фаза 1b — первая активная база игрока (нормализованные string-ключи), для
     * дистанционного просмотра, когда игрок не на базе. null — активных баз нет.
     *
     * @return array<string,mixed>|null
     */
    public function findFirstActiveCell(int $characterId): ?array
    {
        $row = $this->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        if (! is_array($row)) {
            return null;
        }
        return $this->normalizeKeys($row);
    }

    /**
     * @param array<int|string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
