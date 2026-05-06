<?php

namespace App\TaskHandlers\Other;

use App\Models\BiomeWorldObjectMapModel;
use App\Models\BiomeModel;
use App\Models\WorldObjectModel;
use App\Models\MapModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.21 (F2.9 batch-3): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * `process()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 * No Telegram usage — pure DB/world generation.
 */
class WorldObjectGeneratorHandler extends BaseTaskHandler
{
    protected $worldObjectModel;
    protected $biomeWorldObjectMapModel;
    protected $biomeModel;
    protected $mapModel;

    // Дополнительные параметры для усиленной рандомизации
    // Вы можете вынести их в настройки .env или Config, если хочется
    private int $skipFactor = 2;  // Шаг для пропуска ячеек (пример)
    private int $maxDist    = 3;  // Расстояние (в координатах X/Y) для проверки "не слишком ли близко" (пример)
    private int $randomSkipChance = 20; // Процент вероятности (1..100) пропустить ячейку просто так

    public function __construct()
    {
        // Инициализация моделей
        $this->worldObjectModel         = new WorldObjectModel();
        $this->biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $this->biomeModel               = new BiomeModel();
        $this->mapModel                 = new MapModel();
    }

    /**
     * Основной метод, який вызывается для генерации объектов.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // 1) Удаляем все записи, где 'status'='cleared' (те, что игроки полностью лутанули)
        $this->biomeWorldObjectMapModel->where('status', 'cleared')->delete();

        // 2) Берём все типы объектов (world_objects) со status='active'
        $activeObjects = $this->worldObjectModel
            ->where('status', 'active')
            ->findAll();

        // 3) Перебираем каждое описание объекта
        foreach ($activeObjects as $object) {
            $objectId = $object['id'];
            $maxCount = $object['max_count'];

            // Сколько уже стоит на карте (biome_world_object_map.status='active')
            $generatedCount = $this->biomeWorldObjectMapModel
                ->where('world_object_id',  $objectId)
                ->where('status', 'active')
                ->countAllResults();

            // Нужно ли добросить?
            if ($generatedCount < $maxCount) {
                $this->generateObjectByType($object);
            }
        }
    }

    /**
     * Выбираем логику генерации по $object['name_en'].
     */
    protected function generateObjectByType($object)
    {
        // Сколько ещё нужно добавить
        $currentCount   = $this->biomeWorldObjectMapModel
            ->where('world_object_id', $object['id'])
            ->where('status', 'active')
            ->countAllResults();
        $addNewRowCount = $object['max_count'] - $currentCount;
        if ($addNewRowCount <= 0) {
            return;
        }

        // В зависимости от name_en применяем разные алгоритмы
        switch ($object['name_en']) {
            case 'Abandoned truck':
                // Ниже - пример доработанного метода
                $this->generateAbandonedTruck($object, $addNewRowCount);
                break;

            case 'Toolkit':
                $this->generateToolkit($object, $addNewRowCount);
                break;

            case 'Closed warehouse':
                $this->generateClosedWarehouse($object, $addNewRowCount);
                break;

            default:
                // можно расширять
                break;
        }
    }

    /**
     * Пример: генерация "Abandoned truck" с дополнительным рандомом и проверкой расстояния.
     */
    protected function generateAbandonedTruck($object, $addNewRowCount)
    {
        // 1) Разрешённые биомы (JSON массив)
        $allowedBiomes = json_decode($object['biome_id'], true);

        // 2) Собираем все ячейки карты, у которых biome_id в списке
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        // 3) Перемешиваем
        shuffle($availableMapCells);

        // 4) Применим skipFactor: возьмём каждую N-ю ячейку, чтобы не ставить их слишком плотно
        // (Это пример, вы можете использовать иначе)
        $filteredCells = [];
        for ($i = 0; $i < count($availableMapCells); $i += $this->skipFactor) {
            $filteredCells[] = $availableMapCells[$i];
        }

        // 5) Идём по "отфильтрованным" ячейкам
        $created = 0;
        foreach ($filteredCells as $cell) {
            if ($created >= $addNewRowCount) {
                break;
            }

            // Доп. проверка: случайно пропускаем с вероятностью $randomSkipChance
            if (mt_rand(1, 100) <= $this->randomSkipChance) {
                continue;
            }

            // Проверяем, не занята ли клетка
            if (!$this->isCellFree($cell['id'])) {
                continue;
            }

            // Проверяем расстояние до уже активных объектов (чтобы не были слишком рядом)
            if (!$this->distanceCheck($cell, $this->maxDist)) {
                // distanceCheck вернёт false, если "слишком близко" — тогда пропускаем
                continue;
            }

            // Если свободна и достаточно далеко
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
     * Пример "Toolkit" (набор инструментов) - без дополнительных изменений,
     * но вы можете добавить такую же логику distanceCheck / skipFactor и т.д.
     */
    protected function generateToolkit($object, $addNewRowCount)
    {
        // 1) Список биомов
        $allowedBiomes = json_decode($object['biome_id'], true);

        // 2) Собираем ячейки
        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $cells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $cells);
        }

        // "Процентное распределение" из исходного кода
        $percentageDistribution = [
            1 => 1,   2 => 2,   3 => 3,
            5 => 5,   7 => 7,   9 => 9,
            11 => 11, 15 => 15, 18 => 18,
            29 => 29
        ];

        $created = 0;
        $attempts = 0;
        while ($created < $addNewRowCount && $attempts < 5000) {
            $attempts++;

            $randPercent = mt_rand(1, 100);
            $coordYMax   = $this->getCoordinateYByPercentage($randPercent, $percentageDistribution);

            // Фильтруем те, у кого coordinate_y <= $coordYMax
            $possibleCells = array_filter($availableMapCells, function($cell) use ($coordYMax) {
                return ($cell['coordinate_y'] <= $coordYMax);
            });
            if (empty($possibleCells)) {
                continue;
            }

            // Выбираем случайную
            $randomIndex   = array_rand($possibleCells);
            $randomMapCell = $possibleCells[$randomIndex];

            // Проверяем, не занята ли
            $exists = $this->biomeWorldObjectMapModel
                ->where('map_id', $randomMapCell['id'])
                ->where('status', 'active')
                ->first();
            if ($exists) {
                continue;
            }

            // Можно вставить distanceCheck(...) и randomSkipChance, если хотите

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
     * Пример "Closed warehouse"
     * - тоже можно доработать skipFactor, distanceCheck и т.п.
     */
    protected function generateClosedWarehouse($object, $addNewRowCount)
    {
        $allowedBiomes = json_decode($object['biome_id'], true);

        $availableMapCells = [];
        foreach ($allowedBiomes as $biomeId) {
            $mapCells = $this->mapModel->where('biome_id', $biomeId)->findAll();
            $availableMapCells = array_merge($availableMapCells, $mapCells);
        }

        shuffle($availableMapCells);

        $created = 0;
        foreach ($availableMapCells as $cell) {
            if ($created >= $addNewRowCount) {
                break;
            }

            $exists = $this->biomeWorldObjectMapModel
                ->where('map_id', $cell['id'])
                ->where('status', 'active')
                ->first();

            if ($exists) {
                continue;
            }

            // Можно здесь добавить distanceCheck, skipChance, etc.

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
     * Вспомогательный метод: для "Toolkit" логики процента (coordinate_y).
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
        return 1000; // по умолчанию если не попали в список
    }

    /**
     * Проверяет, свободна ли клетка (нет активного объекта в biome_world_object_map).
     */
    protected function isCellFree(int $mapId): bool
    {
        $exists = $this->biomeWorldObjectMapModel
            ->where('map_id', $mapId)
            ->where('status', 'active')
            ->first();
        return $exists ? false : true;
    }

    /**
     * Проверяем, нет ли поблизости другого активного объекта (radius = $maxDist).
     * Возвращает true, если расстояние допустимо, и false, если слишком близко.
     */
    protected function distanceCheck(array $cell, int $maxDist): bool
    {
        // Берём все active-объекты
        $allActive = $this->biomeWorldObjectMapModel
            ->where('status', 'active')
            ->findAll();

        // Координаты текущей проверяемой ячейки
        $cx = $cell['coordinate_x'];
        $cy = $cell['coordinate_y'];

        foreach ($allActive as $row) {
            // Находим координаты уже существующего объекта
            $mapRow = $this->mapModel->find($row['map_id']);
            if (!$mapRow) continue;

            $ox = $mapRow['coordinate_x'];
            $oy = $mapRow['coordinate_y'];

            // Манхэттеновское расстояние (или Евклидово — на ваш выбор)
            // Для примера берём "квадрат" (|dx| <= maxDist, |dy| <= maxDist)
            $dx = abs($ox - $cx);
            $dy = abs($oy - $cy);
            if ($dx <= $maxDist && $dy <= $maxDist) {
                // Слишком близко
                return false;
            }
        }
        // Если не нашли слишком близкого — всё ок
        return true;
    }

}
