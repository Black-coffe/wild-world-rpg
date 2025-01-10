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
        // Удаляем все записи, где поле 'status' равно 'cleared' из таблицы 'biome_world_object_map'
        $this->biomeWorldObjectMapModel->where('status', 'cleared')->delete();

        // Получить все активные объекты мира
        $activeObjects = $this->worldObjectModel->where('status', 'active')->findAll();

        // Перебрать каждый объект
        foreach ($activeObjects as $object) {
            // Получить параметры объекта
            $objectId = $object['id'];
            $maxCount = $object['max_count'];

            // Получить количество сгенерированных объектов в соответствии с картой биомов
            $generatedCount = $this->biomeWorldObjectMapModel->where('world_object_id',  $objectId)->countAllResults();

            // Проверить, нужно ли генерировать новые объекты
            if ($generatedCount < $maxCount) {
                // Вызвать метод генерации объектов для данного типа объекта
                $this->generateObjectByType($object);
            }
        }
    }

    protected function generateObjectByType($object)
    {
        // Получаем список разрешенных биомов для данного объекта
        $allowedBiomes = json_decode($object['biome_id'], true);

        // Получаем количество уже сгенерированных объектов данного типа
        $generatedCount = $this->biomeWorldObjectMapModel->where('world_object_id', $object['id'])->countAllResults();
        $addNewRowCount  = $object['max_count'] - $generatedCount;
        // Если количество сгенерированных объектов меньше максимально допустимого, генерируем новые
        if ($generatedCount < $object['max_count']) {
            // Вызываем соответствующий метод генерации объектов для данного типа
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
            }
        }
    }

    protected function generateAbandonedTruck($resourceObject, $addNewRowCount)
    {
        // Получаем список разрешенных биомов для данного объекта
        $allowedBiomes = json_decode($resourceObject['biome_id'], true);

        // Получаем все строки из таблицы map, соответствующие разрешенным биомам
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // Запускаем цикл для генерации объектов
        for ($i = 0; $i < $addNewRowCount; $i++) {
            // Рандомно выбираем ячейку из доступных
            $randomIndex = array_rand($availableMapCells);
            $randomMapCell = $availableMapCells[$randomIndex];

            // Записываем данные в таблицу biome_world_object_map
            $this->biomeWorldObjectMapModel->insert([
                'biome_id' => $randomMapCell['biome_id'],
                'world_object_id' => $resourceObject['id'],
                'map_id' => $randomMapCell['id'],
                'status' => 'active',
                'object_type' => 'single_use'
            ]);
        }
    }

    protected function generateToolkit($toolkitObject, $addNewRowCount)
    {
        // Получаем список разрешенных биомов для данного объекта
        $allowedBiomes = json_decode($toolkitObject['biome_id'], true);

        // Получаем все строки из таблицы map, соответствующие разрешенным биомам
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // Распределение процента объектов по диапазонам координат
        $percentageDistribution = [
            1 => 1,   // 1% в диапазоне ячеек (coordinate_y <= 100)
            2 => 2,   // 2% в диапазоне ячеек (coordinate_y <= 200 and coordinate_y > 100)
            3 => 3,   // 3% в диапазоне ячеек (coordinate_y <= 300 and coordinate_y > 200)
            5 => 5,   // 5% в диапазоне ячеек (coordinate_y <= 400 and coordinate_y > 300)
            7 => 7,   // 7% в диапазоне ячеек (coordinate_y <= 500 and coordinate_y > 400)
            9 => 9,   // 9% в диапазоне ячеек (coordinate_y <= 600 and coordinate_y > 500)
            11 => 11, // 11% в диапазоне ячеек (coordinate_y <= 700 and coordinate_y > 600)
            15 => 15, // 15% в диапазоне ячеек (coordinate_y <= 800 and coordinate_y > 700)
            18 => 18, // 18% в диапазоне ячеек (coordinate_y <= 900 and coordinate_y > 800)
            29 => 29  // 29% в диапазоне ячеек (coordinate_y > 900)
        ];

        // Запускаем цикл для генерации объектов
        for ($i = 0; $i < $addNewRowCount; $i++) {
            // Рандомно выбираем процент для определения диапазона координат
            $randomPercentage = mt_rand(1, 100);
            $coordinateY = $this->getCoordinateYByPercentage($randomPercentage, $percentageDistribution);

            // Получаем доступные ячейки с соответствующей координатой Y
            $availableCells = array_filter($availableMapCells, function($cell) use ($coordinateY) {
                return $cell['coordinate_y'] <= $coordinateY;
            });

            // Рандомно выбираем ячейку из доступных
            $randomIndex = array_rand($availableCells);
            $randomMapCell = $availableCells[$randomIndex];

            // Записываем данные в таблицу biome_world_object_map
            $this->biomeWorldObjectMapModel->insert([
                'biome_id' => $randomMapCell['biome_id'],
                'world_object_id' => $toolkitObject['id'],
                'map_id' => $randomMapCell['id'],
                'status' => 'active',
                'object_type' => 'single_use'
            ]);
        }
    }

// Функция для получения координаты Y по проценту
    protected function getCoordinateYByPercentage($percentage, $distribution)
    {
        $sum = 0;
        foreach ($distribution as $coordY => $percent) {
            $sum += $percent;
            if ($percentage <= $sum) {
                return $coordY;
            }
        }
        // В случае некорректных данных возвращаем значение по умолчанию
        return 1000;
    }


    protected function generateClosedWarehouse($closedWarehouse, $addNewRowCount)
    {
        // Получаем список разрешенных биомов для данного объекта
        $allowedBiomes = json_decode($closedWarehouse['biome_id'], true);

        // Получаем все строки из таблицы map, соответствующие разрешенным биомам
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // Запускаем цикл для генерации объектов
        for ($i = 0; $i < $addNewRowCount; $i++) {
            // Рандомно выбираем ячейку из доступных
            $randomIndex = array_rand($availableMapCells);
            $randomMapCell = $availableMapCells[$randomIndex];

            // Записываем данные в таблицу biome_world_object_map
            $this->biomeWorldObjectMapModel->insert([
                'biome_id' => $randomMapCell['biome_id'],
                'world_object_id' => $closedWarehouse['id'],
                'map_id' => $randomMapCell['id'],
                'status' => 'active',
                'object_type' => 'single_use'
            ]);
        }
    }
}