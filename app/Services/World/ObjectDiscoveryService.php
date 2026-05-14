<?php
namespace App\Services\World;

use App\Models\MapModel;
use App\Services\Handlers\HandlerRegistry;
use App\TaskHandlers\Objects\AbandonedTruckHandler;
use App\TaskHandlers\Objects\ClosedWarehouseHandler;
use App\TaskHandlers\Objects\ObjectHandlerInterface;
use App\TaskHandlers\Objects\StrategicLootHandler;
use App\TaskHandlers\Objects\ToolkitHandler;
use Throwable;

class ObjectDiscoveryService {
    protected $biomeWorldObjectMapModel;
    protected $worldObjectModel;
    protected $mapModel;

    /**
     * Phase B4 (ADR-023, 2026-05-14) — world_objects.name_en → handler-key
     * (зареєстрований через `#[HandlerKey]` атрибут). Resolve через
     * {@see HandlerRegistry} → fallback на legacy match нижче.
     * Anti-drift guard: `WorldObjectHandlerRegistryConsistencyTest`.
     *
     * @var array<string, string>
     */
    protected array $objectHandlerKeyMap = [
        'Abandoned truck'  => 'world_object_abandoned_truck',
        'Toolkit'          => 'world_object_toolkit',
        'Closed warehouse' => 'world_object_closed_warehouse',
        'Bunker'           => 'world_object_strategic_loot',
        'Technopark'       => 'world_object_strategic_loot',
        'GhostCity'        => 'world_object_strategic_loot',
    ];

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
        // Phase B4 (ADR-023): первая спроба — через HandlerRegistry
        // (`#[HandlerKey]` атрибут на handler-класах). Якщо реєстр недоступний
        // або в ньому немає запису для handler-key — fallback на legacy
        // match нижче. Dual-write вікно B4–B7 за ADR-023.
        if (isset($this->objectHandlerKeyMap[$type])) {
            $handlerKey = $this->objectHandlerKeyMap[$type];
            $registry   = $this->handlerRegistry();
            if ($registry !== null) {
                $entry = $registry->getByKey($handlerKey);
                if ($entry !== null && is_subclass_of($entry->class, ObjectHandlerInterface::class)) {
                    log_message(
                        'debug',
                        "[ObjectDiscovery] type '{$type}' → key '{$handlerKey}' → {$entry->class} (via HandlerRegistry)"
                    );
                    return new $entry->class();
                }
                log_message(
                    'warning',
                    "[ObjectDiscovery] type '{$type}': key '{$handlerKey}' not found in HandlerRegistry — falling back to legacy match"
                );
            }
        }

        return match ($type) {
            'Abandoned truck'  => new AbandonedTruckHandler(),
            'Toolkit'          => new ToolkitHandler(),
            'Closed warehouse' => new ClosedWarehouseHandler(),
            // v0.51.109 — strategic objects (auto-loot з tool check).
            'Bunker', 'Technopark', 'GhostCity' => new StrategicLootHandler(),
            default           => null,
        };
    }

    /**
     * Lazy-доступ до registry через service() helper. Якщо service container
     * недоступний (rannі CLI bootstrap, минімальні unit-тести без CI4
     * environment) — повертаємо null, далі піде legacy match fallback.
     */
    private function handlerRegistry(): ?HandlerRegistry
    {
        if (!function_exists('service')) {
            return null;
        }
        try {
            $candidate = service('handlerRegistry');
        } catch (Throwable) {
            return null;
        }
        return $candidate instanceof HandlerRegistry ? $candidate : null;
    }
}
