<?php

namespace app\TaskHandlers\NPC;

use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use CodeIgniter\CLI\CLI;

/**
 * Класс, который вызывается раз в сутки через CRON
 * и генерирует SandyWolfRaider в таблице npc_spawns по заданной логике.
 */
class SpawnSandyWolfRaidersCron
{
    protected $npcModel;
    protected $npcSpawnModel;
    protected $mapModel;

    public function __construct()
    {
        $this->npcModel      = new NpcModel();
        $this->npcSpawnModel = new NpcSpawnModel();
        $this->mapModel      = new MapModel();
    }

    /**
     * Запуск генерации NPC по ярусам (раз в сутки в 04:14 по Киевскому времени).
     */
    public function run()
    {
        // ✅ Проверяем текущее время (Киев, раз в сутки в 04:14)
        date_default_timezone_set('Europe/Kiev');
        $currentTime = date('H:i');
        if ($currentTime !== '04:14') {
            return;
        }

        $yarusConfig = [
            2 => ['y_min' => 801, 'y_max' => 900, 'npc_count' => 2000, 'min_lvl' => 20,  'max_lvl' => 120],
            3 => ['y_min' => 701, 'y_max' => 800, 'npc_count' => 1800, 'min_lvl' => 120, 'max_lvl' => 220],
            4 => ['y_min' => 601, 'y_max' => 700, 'npc_count' => 1600, 'min_lvl' => 220, 'max_lvl' => 320],
            5 => ['y_min' => 501, 'y_max' => 600, 'npc_count' => 1400, 'min_lvl' => 320, 'max_lvl' => 420],
            6 => ['y_min' => 401, 'y_max' => 500, 'npc_count' => 1200, 'min_lvl' => 420, 'max_lvl' => 520],
            7 => ['y_min' => 301, 'y_max' => 400, 'npc_count' => 800, 'min_lvl' => 520, 'max_lvl' => 620],
            8 => ['y_min' => 201, 'y_max' => 300, 'npc_count' => 600, 'min_lvl' => 620, 'max_lvl' => 720],
            9 => ['y_min' => 101, 'y_max' => 200, 'npc_count' => 350, 'min_lvl' => 720, 'max_lvl' => 900],
        ];

        foreach ($yarusConfig as $yarus => $config) {
            $this->replenishNPCsForYarus($yarus, $config);
        }
    }

    private function replenishNPCsForYarus(int $yarus, array $config)
    {
        // 1️⃣ Находим NPC "SandyWolfRaider"
        $npc = $this->npcModel
            ->where('npc_name_en', 'SandyWolfRaider')
            ->first();

        if (!$npc) {
            return;
        }

        // 2️⃣ Определяем текущее количество живых NPC в этом ярусе
        $currentAlive = $this->npcSpawnModel
            ->where('coordinate_y >=', $config['y_min'])
            ->where('coordinate_y <=', $config['y_max'])
            ->where('status', 'alive')
            ->countAllResults();

        // Если уже достаточно, ничего не делаем
        if ($currentAlive >= $config['npc_count']) {
            return;
        }

        // 3️⃣ Определяем недостающее количество NPC
        $neededNPCs = $config['npc_count'] - $currentAlive;

        // 4️⃣ Определяем подходящие клетки на карте
        $mapRows = $this->mapModel
            ->where('coordinate_y >=', $config['y_min'])
            ->where('coordinate_y <=', $config['y_max'])
            ->where('coordinate_x >=', 0)
            ->where('coordinate_x <=', 999)
            ->whereIn('biome_id', [1, 2, 3, 5, 6, 7, 8, 9])
            ->findAll();

        if (empty($mapRows)) {
            return;
        }

        shuffle($mapRows);
        $selectedCells = array_slice($mapRows, 0, $neededNPCs);

        foreach ($selectedCells as $cell) {
            $this->spawnNPCInCell($cell, $npc, $yarus);
        }
    }

    private function spawnNPCInCell(array $cell, array $npc, int $yarus)
    {
        // Проверяем, что в этой клетке нет живого NPC
        $exists = $this->npcSpawnModel
            ->where('cell_number', $cell['cell_number'])
            ->where('status', 'alive')
            ->countAllResults();

        if ($exists > 0) {
            return;
        }

        // Увеличение характеристик NPC каждые 100 Y
        $levelBoost = ($yarus - 1) * 10;
        $finalLevel = min($npc['level'] + $levelBoost, 999);
        $finalHealth = round($npc['health'] * (1.0 + ($yarus - 1) * 0.2), 2);
        $finalStrength = round($npc['strength'] * (1.0 + ($yarus - 1) * 0.15), 2);
        $finalAgility = round($npc['agility'] * (1.0 + ($yarus - 1) * 0.15), 2);
        $finalDamage = round($npc['damage_value'] * (1.0 + ($yarus - 1) * 0.15), 2);

        // Вставка NPC в базу
        $data = [
            'npc_id'         => (int) $npc['id'],
            'cell_number'    => (int) $cell['cell_number'],
            'coordinate_x'   => (int) $cell['coordinate_x'],
            'coordinate_y'   => (int) $cell['coordinate_y'],
            'current_health' => $finalHealth,
            'level'          => $finalLevel,
            'strength'       => $finalStrength,
            'agility'        => $finalAgility,
            'damage_value'   => $finalDamage,
            'spawned_at'     => date('Y-m-d H:i:s'),
            'status'         => 'alive'
        ];

        $this->npcSpawnModel->insert($data);
    }
}
