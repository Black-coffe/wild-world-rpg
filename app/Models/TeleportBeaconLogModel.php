<?php

namespace App\Models;

use CodeIgniter\Model;

class TeleportBeaconLogModel extends Model
{
    protected $table            = 'teleport_beacon_logs';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'teleport_beacon_id',
        'character_id',
        'from_map_cell_id',
        'teleport_cost',
        'teleported_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true; // включает автозаполнение created_at/updated_at
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Если вам нужна валидация (необязательно):
    protected $validationRules = [
        'teleport_beacon_id' => 'required|integer',
        'character_id'       => 'required|integer',
        'from_map_cell_id'   => 'permit_empty|integer',
        'teleport_cost'      => 'required|integer',
        'teleported_at'      => 'required|valid_date',
    ];

    // Возможны и пользовательские сообщения об ошибках валидации
    protected $validationMessages = [
        // ...
    ];

    // Пример пользовательского метода
    public function getLogsByCharacter($characterId, $limit = 100)
    {
        return $this->where('character_id', $characterId)
            ->orderBy('teleported_at', 'DESC')
            ->limit($limit)
            ->findAll();
    }
}
