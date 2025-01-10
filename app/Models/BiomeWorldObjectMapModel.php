<?php

namespace App\Models;

use CodeIgniter\Model;

class BiomeWorldObjectMapModel extends Model
{
    protected $table = 'biome_world_object_map';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'biome_id', 'world_object_id', 'map_id',
        'status', 'object_type', 'created_at', 'updated_at'
    ];

    protected $returnType = 'array'; // или 'object', если предпочитаете работать с объектами
    protected $useSoftDeletes = false;

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    // Валидационные правила
    protected $validationRules = [
        'biome_id'       => 'required|is_natural_no_zero',
        'world_object_id' => 'required|is_natural_no_zero',
        'map_id'         => 'required|is_natural_no_zero',
        'status'         => 'required|in_list[active,found,cleared]',
        'object_type'    => 'required|in_list[single_use,multi_use]'
    ];

    protected $validationMessages = [];

    // Сценарии валидации, если требуются
    protected $dynamicRules = [];

    /**
     * Получить объекты по идентификатору биома
     *
     * @param int $biomeId ID биома
     * @return array
     */
    public function getByBiomeId($biomeId)
    {
        return $this->where('biome_id', $biomeId)->findAll();
    }

    /**
     * Получить объекты по идентификатору карты
     *
     * @param int $mapId ID карты
     * @return array
     */
    public function getByMapId($mapId)
    {
        return $this->where('map_id', $mapId)->findAll();
    }

    /**
     * Получить все связанные объекты для конкретного объекта мира
     *
     * @param int $worldObjectId ID объекта мира
     * @return array
     */
    public function getByWorldObjectId($worldObjectId)
    {
        return $this->where('world_object_id', $worldObjectId)->findAll();
    }

    public function countGeneratedObjectsByBiomeId($biomeId)
    {
        return $this->where('biome_id', $biomeId)->countAllResults();
    }

    /**
     * Update the status of an object in the map.
     *
     * @param int $id The primary key ID of the object in the biome world object map.
     * @param string $status The new status to set.
     * @return bool Returns true if the update was successful, or false on failure.
     */
    public function updateStatus($id, $status)
    {
        return $this->update($id, ['status' => $status]);
    }
}
