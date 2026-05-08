<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;

/**
 * v0.51.106 (GatherTaskHandler decomp Step 3) — extract cell+biome+resources
 * lookup chain у dedicated final class.
 *
 * BEFORE: handle() робив 2 окремі lookups (getAvailableResources + biomeName).
 * Кожен через 3 SQL (map + biome + resources). Дублювання.
 *
 * AFTER: 1× resolved set: cell + biome + filtered resources.
 * SQL: same 3 queries (map + biome + resources) але без duplication.
 */
final class GatherCellResourceQuery
{
    public function __construct(
        private ?MapModel $mapModel = null,
        private ?BiomeModel $biomeModel = null,
        private ?ResourceModel $resourceModel = null
    ) {
        $this->mapModel      = $mapModel ?? new MapModel();
        $this->biomeModel    = $biomeModel ?? new BiomeModel();
        $this->resourceModel = $resourceModel ?? new ResourceModel();
    }

    /**
     * @return array{
     *   cell: array<string, mixed>|null,
     *   biome: array<string, mixed>|null,
     *   resources: array<int, array<string, mixed>>
     * }
     */
    public function loadCellContext(int $cellNumber, int $characterLevel): array
    {
        $cell = $this->mapModel->where('cell_number', $cellNumber)->first();
        if (!$cell) {
            return ['cell' => null, 'biome' => null, 'resources' => []];
        }

        $biome = $this->biomeModel->find($cell['biome_id']);
        if (!$biome) {
            return ['cell' => $cell, 'biome' => null, 'resources' => []];
        }

        $resources = $this->resourceModel
            ->like('biome_id', (string) $biome['id'], 'both')
            ->where('level_required <=', $characterLevel)
            ->findAll();

        return [
            'cell'      => $cell,
            'biome'     => $biome,
            'resources' => $resources,
        ];
    }
}
