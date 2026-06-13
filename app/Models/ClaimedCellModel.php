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
        'last_warned_at',
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
     * ADR-102 — целевая база для операций сноса/переезда/постройки в мультибэйс-мире.
     * Возвращает map_cell_id базы:
     *  - игрок СТОИТ на своей активной базе (cell_number) → эта база;
     *  - иначе, если у игрока РОВНО ОДНА активная база → она (сохраняет одно-базовое
     *    поведение: снос/переезд работают, даже если игрок не на базе);
     *  - иначе (0 баз; либо ≥2 баз и игрок не на базе) → null (неоднозначно — caller
     *    должен попросить встать на нужную базу).
     */
    public function resolveTargetBaseCell(int $characterId, int $currentCell): ?int
    {
        $active = $this->findActiveCell($characterId, $currentCell);
        if ($active !== null) {
            $mc = $active['map_cell_id'] ?? null;
            return is_numeric($mc) ? (int) $mc : null;
        }
        // Не на базе → если ровно одна активная база, берём её.
        if ($this->countActiveBases($characterId) === 1) {
            $first = $this->findFirstActiveCell($characterId);
            if ($first !== null) {
                $mc = $first['map_cell_id'] ?? null;
                return is_numeric($mc) ? (int) $mc : null;
            }
        }
        return null;
    }

    /**
     * ADR-095/102 — число активных баз игрока.
     */
    public function countActiveBases(int $characterId): int
    {
        $n = $this->where('character_id', $characterId)
            ->where('status', 'active')
            ->countAllResults();
        return is_numeric($n) ? (int) $n : 0;
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
