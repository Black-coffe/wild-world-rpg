<?php

declare(strict_types=1);

namespace App\Services\World;

use App\TaskHandlers\Other\WorldObjectGeneratorHandler;
use Config\Database;
use Throwable;

/**
 * ADR-129 — детект активного стратегического объекта (Bunker / Technopark /
 * GhostCity / IslandFarm / IslandHeart) на клетке игрока.
 *
 * Нужен экрану движения ({@see \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction})
 * чтобы показать кнопку «🔍 Обыскать», и {@see \App\Controllers\Telegram\Commands\Actions\World\StrategicSearchAction}
 * чтобы вызвать лут ({@see \App\TaskHandlers\Objects\StrategicLootHandler}).
 *
 * Зеркало запроса {@see ObjectDiscoveryService::discoverObjectsAtPlayerPosition()},
 * но с фильтром по STRATEGIC_SPAWN_TYPES + status='active'. Row возвращается в той же
 * форме (biome_world_object_map.* + world_objects.*), чтобы StrategicLootHandler::handle()
 * принял его без изменений.
 */
final class StrategicObjectService
{
    /**
     * Активный strategic-объект на клетке (cell_number) или null.
     *
     * @return array<string,mixed>|null
     */
    public function activeStrategicOnCell(int $cellNumber): ?array
    {
        if ($cellNumber <= 0) {
            return null;
        }
        try {
            $db  = Database::connect();
            $row = $db->table('biome_world_object_map')
                ->select('biome_world_object_map.*, world_objects.*')
                ->join('world_objects', 'world_objects.id = biome_world_object_map.world_object_id')
                ->join('map', 'map.id = biome_world_object_map.map_id')
                ->where('map.cell_number', $cellNumber)
                ->where('biome_world_object_map.biome_id = map.biome_id', null, false)
                ->where('biome_world_object_map.status', 'active')
                ->whereIn('world_objects.name_en', WorldObjectGeneratorHandler::STRATEGIC_SPAWN_TYPES)
                ->get();
            if ($row === false) {
                return null;
            }
            $r = $row->getRowArray();

            return is_array($r) ? $r : null;
        } catch (Throwable $e) {
            log_message('error', '[StrategicObjectService] activeStrategicOnCell: ' . $e->getMessage());

            return null;
        }
    }

    /** Имя активного strategic-объекта на клетке (для текста кнопки) или null. */
    public function nameOnCell(int $cellNumber): ?string
    {
        $row = $this->activeStrategicOnCell($cellNumber);
        if ($row === null) {
            return null;
        }
        $name = $row['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Объект';
    }
}
