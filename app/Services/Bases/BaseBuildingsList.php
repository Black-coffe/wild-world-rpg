<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;

/**
 * v0.51.75 (BaseInfoAction decomp Step 3) — extract building enumeration
 * + count + tax + name resolution у dedicated service.
 *
 * Public API:
 *   buildSummary(charId): array{count: int, totalTax: int, list: string}
 *
 * Returns aggregated summary з готовими fields для UX templates:
 *   - count    — total кількість character_buildings rows
 *   - totalTax — sum of tax field across усіх building rows
 *   - list     — Markdown bullet list "- {name_ru}\n" для всіх будівель
 */
final class BaseBuildingsList
{
    private CharacterBuildingModel $characterBuildingModel;
    private BuildingModel $buildingModel;

    public function __construct(
        ?CharacterBuildingModel $characterBuildingModel = null,
        ?BuildingModel $buildingModel = null,
    ) {
        $this->characterBuildingModel = $characterBuildingModel ?? new CharacterBuildingModel();
        $this->buildingModel          = $buildingModel          ?? new BuildingModel();
    }

    /**
     * ADR-095 Фаза 1b: $cellNumber !== null → постройки ТОЛЬКО активной базы (этой
     * ячейки), иначе — все постройки персонажа (legacy-поведение).
     *
     * @return array{count: int, totalTax: int, list: string}
     */
    public function buildSummary(int $characterId, ?int $cellNumber = null): array
    {
        $query = $this->characterBuildingModel->where('character_id', $characterId);
        if ($cellNumber !== null) {
            $query->where('map_cell_id', $cellNumber);
        }
        $buildings = $query->findAll();

        $count    = count($buildings);
        $totalTax = (int) array_sum(array_column($buildings, 'tax'));

        $list = '';
        foreach ($buildings as $b) {
            $bld   = $this->buildingModel->where('id', $b['building_id'])->first();
            $bName = $bld['name_ru'] ?? 'Неизвестное строение';
            $list .= "- {$bName}\n";
        }

        return [
            'count'    => $count,
            'totalTax' => $totalTax,
            'list'     => $list,
        ];
    }
}
