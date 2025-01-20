<?php

namespace App\TaskHandlers\Other;

use App\Models\BiomeWorldObjectMapModel;
use App\Models\BiomeModel;
use App\Models\WorldObjectModel;
use App\Models\MapModel;
use CodeIgniter\Controller;

class WorldObjectGeneratorHandler extends Controller
{
    protected $worldObjectModel;
    protected $biomeWorldObjectMapModel;
    protected $biomeModel;
    protected $mapModel;

    public function __construct()
    {
        // Инициализация моделей
        $this->worldObjectModel = new WorldObjectModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->biomeModel = new BiomeModel();
        $this->mapModel = new MapModel();
    }

    public function process()
    {
        // Удаляем все записи, где поле 'status' равно 'cleared'
        // Это означает, что объект был "очищен" (лут собран).
        $this->biomeWorldObjectMapModel->where('status', 'cleared')->delete();

        // Получить все объекты, у которых status='active' (в таблице world_objects)
        $activeObjects = $this->worldObjectModel
            ->where('status', 'active')
            ->findAll();

        // Перебрать каждый объект
        foreach ($activeObjects as $object) {
            $objectId = $object['id'];
            $maxCount = $object['max_count'];

            // Сколько уже есть в biome_world_object_map
            $generatedCount = $this->biomeWorldObjectMapModel
                ->where('world_object_id',  $objectId)
                ->where('status', 'active')
                ->countAllResults();

            // Нужно ли создавать ещё?
            if ($generatedCount < $maxCount) {
                $this->generateObjectByType($object);
            }
        }
    }

    /**
     * Вспомогательный метод, выбирает конкретный "генератор" для каждого объекта
     * по его name_en.
     */
    protected function generateObjectByType($object)
    {
        // 1) Определяем, сколько ещё нужно добавить
        $currentCount   = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $object['id'])
            ->where('status', 'active')
            ->countAllResults();

        $addNewRowCount = $object['max_count'] - $currentCount;
        if ($addNewRowCount <= 0) {
            return; // Уже хватает
        }

        // 2) В зависимости от name_en — разные генераторы
        switch ($object['name_en']) {
            case 'Abandoned truck':
                $this->generateAbandonedTruck($object, $addNewRowCount);
                break;
            case 'Toolkit':
                $this->generateToolkit($object, $addNewRowCount);
                break;
            case 'Closed warehouse':
                $this->generateClosedWarehouse($object, $addNewRowCount);
                break;
            default:
                // Если у нас будут другие типы, можно расширять
                break;
        }
    }

    /**
     * Пример генерации "Abandoned truck".
     */
    protected function generateAbandonedTruck($object, $addNewRowCount)
    {
        // 1) Получаем разрешённые биомы
        $allowedBiomes = json_decode($object['biome_id'], true);

        // 2) Собираем все ячейки карты, у которых biome_id в списке
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // 3) Перемешиваем ячейки, чтобы не ставить всё в начале массива
        shuffle($availableMapCells);

        // 4) Идём по ячейкам, пока не поставим нужное кол-во
        $created = 0;
        foreach ($availableMapCells as $cell) {
            // Если уже хватило
            if ($created >= $addNewRowCount) {
                break;
            }
            // Проверяем, занята ли клетка
            $exists = $this->biomeWorldObjectMapModel
                ->where('map_id', $cell['id'])
                ->where('status', 'active')
                ->first();

            if ($exists) {
                // Уже есть объект в этой клетке, пропускаем
                continue;
            }

            // Если свободна — вставляем
            $this->biomeWorldObjectMapModel->insert([
                'biome_id'       => $cell['biome_id'],
                'world_object_id'=> $object['id'],
                'map_id'         => $cell['id'],
                'status'         => 'active',
                'object_type'    => 'single_use'
            ]);
            $created++;
        }
    }

    /**
     * Пример генерации "Toolkit" (набор инструментов).
     * Есть дополнительная логика "процента" по coordinate_y.
     */
    protected function generateToolkit($object, $addNewRowCount)
    {
        // 1) Получаем список биомов
        $allowedBiomes = json_decode($object['biome_id'], true);

        // 2) Собираем ячейки карты
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $cells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $cells);
        }

        // --- "Процентное распределение" ---
        // Для упрощения: будем в цикле $addNewRowCount раз генерировать:
        //  - случайный процент
        //  - отфильтровать ячейки, у которых coordinate_y <= некий порог
        //  - выбрать свободную из этого списка (с проверкой).
        $percentageDistribution = [
            1 => 1,   // 1%
            2 => 2,   // 2%
            3 => 3,   // 3%
            5 => 5,   // 5%
            7 => 7,   // 7%
            9 => 9,   // 9%
            11 => 11, // 11%
            15 => 15, // 15%
            18 => 18, // 18%
            29 => 29  // 29%
        ];

        $created = 0;
        $attempts = 0; // чтобы избежать бесконечных циклов
        while ($created < $addNewRowCount && $attempts < 5000) {
            $attempts++;

            // Определяем предел coordinate_y
            $randPercent = mt_rand(1, 100);
            $coordYMax   = $this->getCoordinateYByPercentage($randPercent, $percentageDistribution);

            // Собираем подходящие (и свободные) ячейки
            $possibleCells = array_filter($availableMapCells, function($cell) use ($coordYMax) {
                return ($cell['coordinate_y'] <= $coordYMax);
            });
            if (empty($possibleCells)) {
                // Нет ни одной подходящей
                continue;
            }

            // Выбираем случайную ячейку
            $randomIndex   = array_rand($possibleCells);
            $randomMapCell = $possibleCells[$randomIndex];

            // Проверяем, не занята ли она
            $exists = $this->biomeWorldObjectMapModel
                ->where('map_id', $randomMapCell['id'])
                ->where('status', 'active')
                ->first();
            if ($exists) {
                // Уже занята
                continue;
            }

            // Свободна, вставляем
            $this->biomeWorldObjectMapModel->insert([
                'biome_id'       => $randomMapCell['biome_id'],
                'world_object_id'=> $object['id'],
                'map_id'         => $randomMapCell['id'],
                'status'         => 'active',
                'object_type'    => 'single_use'
            ]);
            $created++;
        }
    }

    /**
     * Сопоставление процентного распределения
     * (1 => 1%, 2 => 2%, 3 => 3%, ...)
     */
    protected function getCoordinateYByPercentage($percentage, $distribution)
    {
        $sum = 0;
        foreach ($distribution as $coordY => $percent) {
            $sum += $percent;
            if ($percentage <= $sum) {
                return $coordY;
            }
        }
        // По умолчанию
        return 1000;
    }

    /**
     * Пример генерации "Closed warehouse".
     * Просто берём ячейки биома, перемешиваем, вставляем если свободно.
     */
    protected function generateClosedWarehouse($object, $addNewRowCount)
    {
        // 1) Список биомов
        $allowedBiomes = json_decode($object['biome_id'], true);

        // 2) Собираем ячейки
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // 3) Перемешиваем
        shuffle($availableMapCells);

        // 4) Идём по списку
        $created = 0;
        foreach ($availableMapCells as $cell) {
            if ($created >= $addNewRowCount) {
                break;
            }
            // Проверяем занятость
            $exists = $this->biomeWorldObjectMapModel
                ->where('map_id', $cell['id'])
                ->where('status', 'active')
                ->first();

            if ($exists) {
                // Занята, пропускаем
                continue;
            }

            // Свободна, вставляем
            $this->biomeWorldObjectMapModel->insert([
                'biome_id'       => $cell['biome_id'],
                'world_object_id'=> $object['id'],
                'map_id'         => $cell['id'],
                'status'         => 'active',
                'object_type'    => 'single_use'
            ]);
            $created++;
        }
    }
}
