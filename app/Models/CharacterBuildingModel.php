<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterBuildingModel extends Model
{
    protected $table = 'character_buildings';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;

    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'character_id',
        'building_id',
        'faction_id',
        'map_cell_id',
        'amount',
        'character_level_during_construction',
        'hp',
        'level',
        'built_at',
        'building_type',
        'tax',
        'usage',
        'last_tax_collected',
        'tax_collection_status', // Новое поле
        'disappearance_date',
        'usage_count',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'character_id' => 'required|integer',
        'building_id' => 'required|integer',
        'faction_id' => 'permit_empty|integer',
        'map_cell_id' => 'required|integer',
        'amount' => 'required|integer',
        'character_level_during_construction' => 'required|integer',
        'hp' => 'required|integer',
        'level' => 'required|integer',
        'built_at' => 'required|valid_date',
        'building_type' => 'required|in_list[military,residential,farming,resource,engineering,defensive]',
        'tax' => 'required|integer',
        'usage' => 'required|in_list[personal,collective,all]',
        'last_tax_collected' => 'permit_empty|valid_date',
        'tax_collection_status' => 'permit_empty|in_list[SUCCESS,FAILURE]', // Новое правило валидации
        'disappearance_date' => 'permit_empty|valid_date',
        'usage_count' => 'permit_empty|integer',
    ];

    protected $validationMessages = [];
    protected $skipValidation = false;

    public function getBuildingsByCharacter($characterId)
    {
        return $this->where('character_id', $characterId)->findAll();
    }

    public function getBuildingsByMapCell($mapCellId)
    {
        return $this->where('map_cell_id', $mapCellId)->findAll();
    }
}
