<?php

namespace app\TaskHandlers\NPC;

use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use CodeIgniter\CLI\CLI;

/**
 * Класс, который вызывается раз в час через CRON
 * и генерирует SandyWolfRaider в таблице npc_spawns по заданной логике.
 */
class SpawnSandyWolfRaidersCron
{
    /** @var NpcModel */
    protected $npcModel;

    /** @var NpcSpawnModel */
    protected $npcSpawnModel;

    /** @var MapModel */
    protected $mapModel;

    public function __construct()
    {
        $this->npcModel      = new NpcModel();
        $this->npcSpawnModel = new NpcSpawnModel();
        $this->mapModel      = new MapModel();
    }

    /**
     * Метод, который надо вызывать раз в час (например, cron job).
     */
    public function run()
    {
        // ✅ Проверяем текущий день недели и время
        date_default_timezone_set('Europe/Kiev');
        $dayOfWeek = date('N'); // 1 (Пн) ... 7 (Вс)
        $currentTime = date('H:i');

        if (!in_array($dayOfWeek, [4, 7]) || $currentTime !== '04:14') {
            return;
        }

        // 1️⃣ Находим NPC "SandyWolfRaider" в таблице npcs
        $sandyWolf = $this->npcModel
            ->where('npc_name_en', 'SandyWolfRaider')
            ->first();

        if (!$sandyWolf) {
            return;
        }

        $baseHealth = (float) $sandyWolf['health'];
        $npcId      = (int)   $sandyWolf['id'];

        // 2) Собираем ячейки карты, удовлетворя условиям:
        //    - Y от 800 до 900
        //    - X от 0 до 999
        //    - biome_id IN (2, 6, 9)
        $validBiomes = [2, 6, 9]; // горы(2), поля(6), пустыни(9)

        // Запрос к map
        $mapRows = $this->mapModel
            ->where('coordinate_y >=', 800)
            ->where('coordinate_y <=', 900)
            ->where('coordinate_x >=', 0)
            ->where('coordinate_x <=', 999)
            ->whereIn('biome_id', $validBiomes)
            ->findAll();

        $cellCount = count($mapRows);

        if ($cellCount === 0) {
            return;
        }

        // 3) Определяем нужное кол-во спавнов: cellCount / 15
        $spawnsNeeded = (int) floor($cellCount / 15);
        if ($spawnsNeeded <= 0) {
            return;
        }

        // -- ДОПОЛНИТЕЛЬНЫЙ ФУНКЦИОНАЛ --

        // A) Удаляем все "dead" записи этого NPC (чтобы не копились)
        $deleted = $this->npcSpawnModel
            ->where('npc_id', $npcId)
            ->where('status', 'dead')
            ->delete();

        // B) Смотрим, сколько уже "alive" SandyWolfRaider в npc_spawns
        $currentAlive = $this->npcSpawnModel
            ->where('npc_id', $npcId)
            ->where('status', 'alive')
            ->countAllResults();

        // Если уже есть столько же или больше — не спавним новых
        if ($currentAlive >= $spawnsNeeded) {
            return;
        }

        // C) Вычисляем, сколько нужно доспавнить
        $difference = $spawnsNeeded - $currentAlive;

        // 4) Случайным образом выбираем ячейки, но исключаем те, где уже есть SandyWolf со status='alive'.
        //    4.1 перемешаем mapRows
        shuffle($mapRows);

        // 4.2 Сформируем список "занятых" coordinate_x/coordinate_y
        //     (можно также ориентироваться на cell_number, если хотим исключить любой NPC в той же ячейке)
        $occupiedCells = $this->npcSpawnModel
            ->select('cell_number')
            ->where('npc_id', $npcId)
            ->where('status', 'alive')
            ->findAll();

        $occupiedMap = [];
        foreach ($occupiedCells as $oc) {
            $occupiedMap[$oc['cell_number']] = true;
        }

        // 4.3 Пробежимся по mapRows, пропуская клетки, где уже есть SandyWolf.
        $freeCells = [];
        foreach ($mapRows as $cell) {
            $cn = (int) $cell['cell_number'];
            if (!isset($occupiedMap[$cn])) {
                // значит в этой ячейке нет живого SandyWolfRaider
                $freeCells[] = $cell;
            }
        }

        $freeCount = count($freeCells);

        if ($freeCount === 0) {
            return;
        }

        // 4.4 берем нужное кол-во (difference) клеток из freeCells
        $selectedCells = array_slice($freeCells, 0, $difference);
        $selectedCount = count($selectedCells);

        // 5) Для каждой выбранной ячейки вставляем запись
        $spawnedCount = 0;
        foreach ($selectedCells as $cell) {
            // Генерируем случайное увеличение здоровья от +1% до +100% базового
            // => rand(1..100)/100 => [0.01..1.00]
            $randomFactor = (float) rand(1, 100) / 100.0;
            $finalHealth  = $baseHealth * (1.0 + $randomFactor);
            $finalHealth  = round($finalHealth, 2);

            $data = [
                'npc_id'         => $npcId,
                'cell_number'    => (int) $cell['cell_number'],
                'coordinate_x'   => (int) $cell['coordinate_x'],
                'coordinate_y'   => (int) $cell['coordinate_y'],
                'current_health' => $finalHealth,
                'spawned_at'     => date('Y-m-d H:i:s'),
                'status'         => 'alive'
            ];

            $this->npcSpawnModel->insert($data);
            $spawnedCount++;
        }

    }
}
