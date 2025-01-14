<?php
namespace App\Services\World;

use App\TaskHandlers\Objects\AbandonedTruckHandler;
use App\TaskHandlers\Objects\ClosedWarehouseHandler;
use App\TaskHandlers\Objects\ToolkitHandler;

class ObjectDiscoveryService {
    protected $biomeWorldObjectMapModel;
    protected $worldObjectModel;

    public function __construct($biomeWorldObjectMapModel, $worldObjectModel) {
        $this->biomeWorldObjectMapModel = $biomeWorldObjectMapModel;
        $this->worldObjectModel = $worldObjectModel;
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

    protected function getHandler($type) {
        switch ($type) {
            case 'Abandoned truck':
                return new AbandonedTruckHandler();
            case 'Toolkit':
                return new ToolkitHandler();
            case 'Closed warehouse':
                return new ClosedWarehouseHandler();
            default:
                return null;  // Возвращаем null, если обработчик не найден
        }
    }
}
