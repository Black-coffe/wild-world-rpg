<?php

declare(strict_types=1);

namespace App\Services\Player\Gather;

use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsReaderTrait;

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
    use GameSettingsReaderTrait;

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

        // E16 (ADR-116) — широтный гейт эксклюзивных ресурсов пояса. Существующие ресурсы имеют
        // min_y=NULL → попадают всегда (byte-identical). Ресурс с min_y/max_y добывается ТОЛЬКО при
        // coordinate_y ∈ [min_y, max_y) И killswitch world.belt_resources.enabled=ON; иначе скрыт.
        $biomeId = (int) $biome['id'];
        $query   = $this->resourceModel
            ->where("FIND_IN_SET({$biomeId}, biome_id) > 0", null, false)
            ->where('level_required <=', $characterLevel);

        if ($this->gsBool('world.belt_resources.enabled', false)) {
            $y = is_array($cell) && is_numeric($cell['coordinate_y'] ?? null) ? (int) $cell['coordinate_y'] : -1;
            $query->groupStart()
                ->where('min_y', null)
                ->orGroupStart()
                    ->where('min_y <=', $y)
                    ->where('max_y >', $y)
                ->groupEnd()
            ->groupEnd();
        } else {
            $query->where('min_y', null); // dormant: скрыть широтно-гейтнутые ресурсы
        }

        $resources = $query->findAll();

        return [
            'cell'      => $cell,
            'biome'     => $biome,
            'resources' => $resources,
        ];
    }
}
