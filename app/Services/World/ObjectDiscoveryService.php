<?php
namespace App\Services\World;

use App\TaskHandlers\Objects\AbandonedTruckHandler;
use App\TaskHandlers\Objects\ClosedWarehouseHandler;
use App\TaskHandlers\Objects\StrategicLootHandler;
use App\TaskHandlers\Objects\ToolkitHandler;
use App\Models\MapModel;

class ObjectDiscoveryService {
    protected $biomeWorldObjectMapModel;
    protected $worldObjectModel;
    protected $mapModel;

    public function __construct($biomeWorldObjectMapModel, $worldObjectModel) {
        $this->biomeWorldObjectMapModel = $biomeWorldObjectMapModel;
        $this->worldObjectModel = $worldObjectModel;
        $this->mapModel = new MapModel();
    }

    public function discoverObjects($exploredCells, $character) {
        foreach ($exploredCells as $cell) {
            $objects = $this->biomeWorldObjectMapModel
                ->select('biome_world_object_map.*, world_objects.*')
                ->join('world_objects', 'world_objects.id = biome_world_object_map.world_object_id')
                ->where('biome_world_object_map.map_id', $cell['map_id'])
                ->where('biome_world_object_map.biome_id', $cell['biome_id'])
                ->findAll();

            foreach ($objects as $object) {
                $handler = $this->getHandler($object['name_en']);
                if ($handler) {
                    $handler->handle($object, $cell, $character);
                }
            }
        }
    }

    /**
     * НОВЫЙ метод - обнаруживает объекты только в той ячейке, где
     * реально находится персонаж (cell_number).
     */
    public function discoverObjectsAtPlayerPosition($character)
    {
        // 1) Находим map-ячейку, где персонаж
        $cell = $this->mapModel
            ->where('cell_number', $character['cell_number'])
            ->first();

        if (!$cell) {
            // Персонаж вне диапазона карты?
            return;
        }

        // 2) Ищем объекты с status='active' на этой ячейке
        $objects = $this->biomeWorldObjectMapModel
            ->select('biome_world_object_map.*, world_objects.*')
            ->join('world_objects', 'world_objects.id = biome_world_object_map.world_object_id')
            ->where('biome_world_object_map.map_id',  $cell['id'])
            ->where('biome_world_object_map.biome_id', $cell['biome_id'])
            ->where('biome_world_object_map.status', 'active')
            ->findAll();

        // 3) Для каждого объекта - определить handler
        foreach ($objects as $object) {
            $handler = $this->getHandler($object['name_en']);
            if ($handler) {
                $handler->handle($object, $cell, $character);
            }
        }
    }

    protected function getHandler($type) {
        return match ($type) {
            'Abandoned truck'  => new AbandonedTruckHandler(),
            'Toolkit'          => new ToolkitHandler(),
            'Closed warehouse' => new ClosedWarehouseHandler(),
            // v0.51.109 — strategic objects (auto-loot з tool check).
            'Bunker', 'Technopark', 'GhostCity' => new StrategicLootHandler(),
            default           => null,
        };
    }
}
