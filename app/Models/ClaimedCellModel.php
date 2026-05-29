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
}
