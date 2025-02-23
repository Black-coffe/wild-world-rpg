<?php
namespace App\Services\World;

use App\Models\NpcSpawnModel;

class NpcLocatorService
{
    protected $npcSpawnModel;

    public function __construct()
    {
        $this->npcSpawnModel = new NpcSpawnModel();
    }

    /**
     * Возвращает массив NPC, находящихся в 8 соседних ячейках вокруг (pX, pY).
     * (Диапазон ±1 по X и Y, исключая саму позицию игрока.)
     */
    public function getNpcsInAdjacentCells(int $pX, int $pY): array
    {
        // Сформируем прямоугольный диапазон ±1 по x/y:
        $xMin = $pX - 1;
        $xMax = $pX + 1;
        $yMin = $pY - 1;
        $yMax = $pY + 1;

        // Выбираем «alive» NPC только в этой 3×3 зоне
        // (которая включает позицию игрока).
        $spawnRows = $this->npcSpawnModel
            ->where('status', 'alive')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        // Оставим только те, что НЕ в клетке игрока (pX, pY).
        // Ведь там мы обычно выводим иконку самого игрока.
        // Таким образом останется ровно 8 клеток вокруг.
        $filtered = [];
        foreach ($spawnRows as $row) {
            if (!($row['coordinate_x'] == $pX && $row['coordinate_y'] == $pY)) {
                $filtered[] = $row;
            }
        }

        // Дополнительно можно вернуть массив вида "x_y" => $row
        // для удобной подстановки при отрисовке карты:
        $result = [];
        foreach ($filtered as $npc) {
            $key = "{$npc['coordinate_x']}_{$npc['coordinate_y']}";
            $result[$key] = $npc;
        }

        return $result;
    }
}
