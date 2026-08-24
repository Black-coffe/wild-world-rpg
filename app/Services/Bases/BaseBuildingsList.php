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
 *   demolishRows(charId, cellNumber): list<array{id,buildingId,name,level,tax,amount}>
 *
 * Returns aggregated summary з готовими fields для UX templates:
 *   - count    — total кількість character_buildings rows
 *   - totalTax — sum of tax field across усіх building rows
 *   - list     — Markdown bullet list "- {name_ru}\n" для всіх будівель
 *
 * chat-requests-batch-07 — `demolishRows()` даёт то же самое (дубли видны отдельными
 * строками, суммарный налог считается тем же способом), но по-СТРОЧНО с `id` строки
 * `character_buildings` — `buildSummary()` схлопывает состав в единую markdown-строку
 * без идентификаторов, для кнопки «Снести» этого недостаточно.
 *
 * 🔴 Ревью 24.08.2026 (BLOCK): вторая постройка ТОГО ЖЕ типа на ТОЙ ЖЕ базе не создаёт
 * новую строку — `GenericBuildingCompletionHandler` инкрементит `amount` на СУЩЕСТВУЮЩЕЙ
 * строке (стек). `demolishRows()` обязан отдавать `amount`, иначе UI показывает «одну
 * постройку» там, где их несколько, и снос строки сносит весь стек разом.
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

    /**
     * По-строчный состав построек ОДНОЙ базы с `id` строки `character_buildings` —
     * источник для экрана сноса (`DemolishBuildingAction`): каждая СТРОКА — своя
     * кнопка, а `amount` в строке — сколько построек этого типа в ней стоит (стек).
     * Имя — через {@see BuildingModel::rusName()} (правило проекта, `db-schema.md`),
     * не напрямую `name_ru`. Здания подтягиваются ОДНИМ запросом (`whereIn`), не в
     * цикле — `where()->first()` на общем экземпляре модели внутри цикла копит
     * условия между итерациями (устоявшаяся ловушка билдера CI4 в этом проекте).
     *
     * @return list<array{id:int, buildingId:int, name:string, level:int, tax:int, amount:int}>
     */
    public function demolishRows(int $characterId, int $cellNumber): array
    {
        $buildings = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('map_cell_id', $cellNumber)
            ->findAll();

        $buildingIds = [];
        foreach ($buildings as $raw) {
            $b  = is_array($raw) ? $raw : (array) $raw;
            $id = is_numeric($b['building_id'] ?? null) ? (int) $b['building_id'] : 0;
            if ($id > 0) {
                $buildingIds[$id] = true;
            }
        }
        $buildingIds = array_keys($buildingIds);
        $names = [];
        if ($buildingIds !== []) {
            foreach ($this->buildingModel->whereIn('id', $buildingIds)->findAll() as $bldRaw) {
                /** @var array<string,mixed> $bld */
                $bld         = is_array($bldRaw) ? $bldRaw : (array) $bldRaw;
                $id          = is_numeric($bld['id'] ?? null) ? (int) $bld['id'] : 0;
                $nameEn      = isset($bld['name_en']) && is_string($bld['name_en']) ? $bld['name_en'] : '';
                $names[$id]  = BuildingModel::rusName($bld, $nameEn !== '' ? $nameEn : 'Неизвестное строение');
            }
        }

        $rows = [];
        foreach ($buildings as $raw) {
            /** @var array<string,mixed> $b */
            $b          = is_array($raw) ? $raw : (array) $raw;
            $buildingId = is_numeric($b['building_id'] ?? null) ? (int) $b['building_id'] : 0;

            $rows[] = [
                'id'         => is_numeric($b['id'] ?? null) ? (int) $b['id'] : 0,
                'buildingId' => $buildingId,
                'name'       => $names[$buildingId] ?? 'Неизвестное строение',
                'level'      => is_numeric($b['level'] ?? null) ? max(1, (int) $b['level']) : 1,
                'tax'        => is_numeric($b['tax'] ?? null) ? (int) $b['tax'] : 0,
                'amount'     => is_numeric($b['amount'] ?? null) ? max(1, (int) $b['amount']) : 1,
            ];
        }

        return $rows;
    }
}
