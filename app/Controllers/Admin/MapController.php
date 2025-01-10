<?php

namespace App\Controllers\Admin;

use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ActionLogModel;
use CodeIgniter\Controller;
ini_set('memory_limit', '8G');

class MapController extends Controller
{
    protected $biomeModel;
    protected $mapModel;
    protected $actionLogModel;

    public function __construct()
    {
        $this->biomeModel = new BiomeModel();
        $this->mapModel = new MapModel();
        $this->actionLogModel = new ActionLogModel();
    }

    public function index()
    {
        // Получаем все биомы из модели
        $biomes = $this->biomeModel->findAll();
        $statusLog = $this->actionLogModel->findAll();

        // Отправляем данные о биомах в представление
        return view('admin/map_index', [
            'biomes' => $biomes,
            'statusLog' => $statusLog,
            'title' => 'Список всех Биомов в игре'
        ]);
    }

    public function generateMap()
    {
        // Создаем экземпляр модели
        $mapModel = new MapModel();

        // Генерируем 1 000 000 строк с пустыми значениями biome_id
        $totalRows = 1000000;
        $batchSize = 100000;
        $rowsPerSide = sqrt($totalRows); // Количество строк/столбцов в квадратной сетке

        // Начальные значения для координат X и Y
        $initialX = 1;
        $initialY = 1;

        for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
            $data = [];
            for ($i = 0; $i < $batchSize; $i++) {
                $cellNumber = $offset + $i + 1; // Начинаем с 1

                // Вычисляем координаты X и Y
                $x = $initialX + ($cellNumber - 1) % $rowsPerSide;
                $y = $initialY + floor(($cellNumber - 1) / $rowsPerSide);

                // Проверка на окончание генерации
                if ($cellNumber > $totalRows) {
                    break;
                }

                $data[] = [
                    'cell_number' => $cellNumber,
                    'coordinate_x' => $x,
                    'coordinate_y' => $y,
                    'biome_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }

            // Вставляем данные в таблицу
            $mapModel->insertBatch($data);
        }

        // Обновляем статус в таблице action_log
        $this->actionLogModel
            ->where('action_name', 'map')
            ->set(['action_status' => 'Done'])
            ->update();

        // Выводим сообщение об успешном выполнении
        session()->setFlashdata('success', '1 000 000 строк успешно добавлены в таблицу map и статус в action_log обновлен.');
        return redirect()->to(site_url('admin/map'));
    }

    // Метод отвечающий за генерацию Биоме с реками
    public function generateRivers()
    {
        // Находим запись о реках в таблице биомов
        $riverBiome = $this->biomeModel->where('name', 'Реки')->first();

        if (!$riverBiome) {
            // Выводим сообщение об ошибке, если запись о реках не найдена
            session()->setFlashdata('error', 'Запись о реках не найдена в таблице биомов.');
            return redirect()->to(site_url('admin/map'));
        }

        // Получаем значение occurrence_rate для рек
        $occurrenceRate = $riverBiome['occurrence_rate'];

        // Рассчитываем количество ячеек для рек в соответствии с occurrence_rate
        $totalCells = 1000000; // Общее количество ячеек
        $riverCells = ceil($totalCells * ($occurrenceRate / 100)); // Количество ячеек для рек

        // Делим количество ячеек на три
        $riverCellsPerRiver = ceil($riverCells / 3);

        // Генерация трех рек
        for ($i = 0; $i < 3; $i++) {
            // Генерация реки с уникальными начальными координатами
            $this->generateRiver($riverBiome['id'], $riverCellsPerRiver);
        }

        // Выводим сообщение об успешном выполнении
        session()->setFlashdata('success', 'Реки успешно добавлены в карту.');
        return redirect()->to(site_url('admin/map'));
    }

    protected function generateRiver($biomeId, $riverCells)
    {
        $remainingCells = $riverCells;
        $direction = 'N';

        // Генерация начальных координат для каждого сегмента реки
        $initialX = rand(1, 1000);
        $initialY = rand(1, 1000);

        for ($j = 0; $j < $riverCells && $remainingCells > 0; $j++) {
            $shiftX = rand(-3, 3);
            $shiftY = rand(-3, 3);

            $newX = $initialX + $shiftX;
            $newY = $initialY + $shiftY;

            if ($newX < 1 || $newX > 1000 || $newY < 1 || $newY > 1000) {
                continue;
            }

            // Проверяем, заполнена ли ячейка или ее соседи
            if ($this->mapModel->isCellFilled($newX, $newY) ||
                $this->mapModel->isCellFilled($newX + $shiftY, $newY - $shiftX) ||
                $this->mapModel->isCellFilled($newX - $shiftY, $newY + $shiftX)) {
                $shiftX = -$shiftX;
                $shiftY = -$shiftY;
                $newX = $initialX + $shiftX;
                $newY = $initialY + $shiftY;
            }

            // Обновляем запись в таблице map
            $this->mapModel->updateCellBiomeId($newX, $newY, $biomeId);

            // Обновляем начальные координаты для следующей ячейки
            $initialX = $newX;
            $initialY = $newY;

            $remainingCells--;
        }
    }

    protected function getNextDirection($direction)
    {
        $possibleDirections = ['N', 'S', 'E', 'W'];
        $currentIndex = array_search($direction, $possibleDirections);

        // Выбираем случайное направление из соседних
        $nextIndex = rand($currentIndex - 1, $currentIndex + 1);
        if ($nextIndex < 0) {
            $nextIndex = 3;
        } elseif ($nextIndex > 3) {
            $nextIndex = 0;
        }

        // Проверка на противоположное направление
        if ($possibleDirections[$nextIndex] == $this->getOppositeDirection($direction)) {
            // Если следующее направление противоположно текущему,
            // выбираем другое случайное направление
            $nextIndex = rand(0, 3);
        }

        return $possibleDirections[$nextIndex];
    }

    protected function getOppositeDirection($direction)
    {
        switch ($direction) {
            case 'N':
                return 'S';
            case 'S':
                return 'N';
            case 'E':
                return 'W';
            case 'W':
                return 'E';
        }

        return null;
    }
}
