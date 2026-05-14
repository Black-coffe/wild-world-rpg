<?php

namespace App\Models;

use CodeIgniter\Model;

class WorldObjectModel extends Model
{
    protected $table = 'world_objects';
    protected $primaryKey = 'id';

    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name', 'name_en',
        'handler_key',  // Phase B6 (ADR-023) — registry dispatch key, NULL = legacy fallback.
        'description', 'biome_id', 'max_count',
        'discovery_tools', 'contents', 'respawn_time', 'status',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $validationRules = [
        'name' => 'required|string|max_length[255]',
        'name_en' => 'required|string|max_length[255]',
        'biome_id' => 'permit_empty',
        'max_count' => 'required|is_natural',
        'respawn_time' => 'required|is_natural',
        'status' => 'required|in_list[active,inactive]'
    ];

    protected $skipValidation = false;

    // Автоматическое преобразование JSON-строк в массивы после извлечения данных из базы
    protected function afterFind(array $data) {
        foreach ($data['data'] as $key => $value) {
            if ($key === 'contents' || $key === 'discovery_tools') {
                $data['data'][$key] = json_decode($value, true) ?? $value;
            }
        }
        return $data;
    }

    protected function parseJson(array $data) {
        if (isset($data['data']['discovery_tools'])) {
            $data['data']['discovery_tools'] = json_decode($data['data']['discovery_tools'], true);
        }
        if (isset($data['data']['contents'])) {
            $data['data']['contents'] = json_decode($data['data']['contents'], true);
        }
        return $data;
    }
    protected function beforeInsert(array $data) {
        $data = $this->prepareJson($data);
        return $data;
    }

    protected function beforeUpdate(array $data) {
        $data = $this->prepareJson($data);
        return $data;
    }

}
