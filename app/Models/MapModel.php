<?php
// app/Models/MapModel.php

namespace App\Models;

use CodeIgniter\Model;
ini_set('memory_limit', '1G');
class MapModel extends Model
{
    protected $table = 'map';
    protected $primaryKey = 'id';
    protected $allowedFields = ['cell_number', 'coordinate_x', 'coordinate_y', 'biome_id', 'created_at', 'updated_at'];

    // Check if a cell is filled with a biome
    public function isCellFilled($x, $y)
    {
        return $this->where(['coordinate_x' => $x, 'coordinate_y' => $y])->first() !== null;
    }

    // Fill a cell with a biome or update if it already exists
    public function fillOrUpdateCell($x, $y, $biomeId)
    {
        $cell = $this->where(['coordinate_x' => $x, 'coordinate_y' => $y])->first();
        if ($cell) {
            // If cell exists, update biome_id
            return $this->update($cell['id'], ['biome_id' => $biomeId]);
        } else {
            // If cell doesn't exist, insert new record
            return $this->insert([
                'coordinate_x' => $x,
                'coordinate_y' => $y,
                'biome_id' => $biomeId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Update biome_id for a cell
    public function updateCellBiomeId($x, $y, $biomeId)
    {
        return $this->set(['biome_id' => $biomeId])
            ->where(['coordinate_x' => $x, 'coordinate_y' => $y])
            ->update();
    }

    public function fetchAllMapData()
    {
        return $this->select('coordinate_x, coordinate_y, biome_id')
            ->findAll();
    }

    /**
     * Get cells surrounding a specified cell number within a given range.
     *
     * @param int $cellNumber The central cell number.
     * @param int $range The range of cells to include around the central cell.
     * @return array The surrounding cells information.
     */
    public function getSurroundingCells($cellNumber, $range)
    {
        $centralCell = $this->where('cell_number', $cellNumber)->first();
        if (!$centralCell || !isset($centralCell['coordinate_x']) || !isset($centralCell['coordinate_y'])) {
            log_message('error', "Central cell not found or missing coordinates for cell number: {$cellNumber}");
            return []; // Return empty array if central cell is not found or coordinates are missing
        }

        $x = $centralCell['coordinate_x'];
        $y = $centralCell['coordinate_y'];

        $xMin = $x - $range;
        $xMax = $x + $range;
        $yMin = $y - $range;
        $yMax = $y + $range;

        // Adjust the query to include biome_name by joining with the biomes table, assuming biomes table has 'name' field
        $cells = $this->select('map.cell_number, map.coordinate_x as x, map.coordinate_y as y, biomes.name as biome_name')
            ->join('biomes', 'map.biome_id = biomes.id', 'left')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        return array_filter($cells, function ($cell) use ($cellNumber) {
            return $cell['cell_number'] != $cellNumber; // Exclude the central cell
        });
    }

    /**
     * Возвращает соседние ячейки для заданной ячейки.
     * @param int $cellNumber номер центральной ячейки
     * @return array массив с информацией о соседних ячейках
     */
    public function getNeighboringCells(int $cellNumber)
    {
        // Получаем координаты центральной ячейки
        $centralCell = $this->find($cellNumber);
        if (!$centralCell) {
            return []; // Возвращаем пустой массив, если ячейка не найдена
        }

        $x = $centralCell['coordinate_x'];
        $y = $centralCell['coordinate_y'];

        // Определяем координаты соседних ячеек
        $xMin = $x - 1;
        $xMax = $x + 1;
        $yMin = $y - 1;
        $yMax = $y + 1;

        // Выполняем запрос к базе данных для выборки соседних ячеек
        return $this->select('cell_number, coordinate_x, coordinate_y, biome_id')
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();
    }
}
