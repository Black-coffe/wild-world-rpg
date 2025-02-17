<?php

namespace App\TaskHandlers\NPC;

use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use CodeIgniter\CLI\CLI;

/**
 * Класс, который вызывается раз в сутки через CRON
 * и генерирует SandyWolfRaider в таблице npc_spawns по заданной логике.
 *
 * Теперь базовые характеристики NPC (уровень, сила, ловкость, интеллект, урон, броня)
 * берутся из таблицы npcs – их корректировка производится там, а при спавне
 * изменяется только показатель здоровья (current_health), увеличенный в зависимости от яруса.
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
        // Устанавливаем таймзону и проверяем время (Киев, 04:14)
        date_default_timezone_set('Europe/Kiev');
        $currentTime = date('H:i');
        if ($currentTime !== '04:14') {
            return;
        }

        // Конфигурация ярусов – диапазоны по оси Y и требуемое количество NPC.
        // Здесь значения min_lvl и max_lvl больше не используются для масштабирования остальных характеристик,
        // поскольку они берутся из таблицы npcs, а при спавне меняется только здоровье.
        $yarusConfig = [
            2 => ['y_min' => 801, 'y_max' => 900, 'npc_count' => 2000],
            3 => ['y_min' => 701, 'y_max' => 800, 'npc_count' => 1800],
            4 => ['y_min' => 601, 'y_max' => 700, 'npc_count' => 1600],
            5 => ['y_min' => 501, 'y_max' => 600, 'npc_count' => 1400],
            6 => ['y_min' => 401, 'y_max' => 500, 'npc_count' => 1200],
            7 => ['y_min' => 301, 'y_max' => 400, 'npc_count' => 800],
            8 => ['y_min' => 201, 'y_max' => 300, 'npc_count' => 600],
            9 => ['y_min' => 101, 'y_max' => 200, 'npc_count' => 350],
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

        // 2️⃣ Определяем текущее количество живых NPC в этом ярусе (по координате Y)
        $currentAlive = $this->npcSpawnModel
            ->where('coordinate_y >=', $config['y_min'])
            ->where('coordinate_y <=', $config['y_max'])
            ->where('status', 'alive')
            ->countAllResults();

        if ($currentAlive >= $config['npc_count']) {
            return;
        }

        // 3️⃣ Определяем недостающее количество NPC
        $neededNPCs = $config['npc_count'] - $currentAlive;

        // 4️⃣ Выбираем подходящие клетки на карте в заданном диапазоне
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

    /**
     * Генерирует (спавнит) NPC в указанной клетке с параметрами,
     * при этом для всех характеристик, кроме здоровья, используются базовые значения из таблицы npcs.
     * Текущее здоровье (current_health) NPC вычисляется как:
     * finalHealth = базовое здоровье * (1.0 + (yarus - 1) * 0.2)
     *
     * @param array $cell  Данные клетки карты.
     * @param array $npc   Данные NPC из таблицы npcs (шаблон).
     * @param int   $yarus Номер яруса.
     */
    private function spawnNPCInCell(array $cell, array $npc, int $yarus)
    {
        // Проверяем, что в этой клетке ещё нет живого NPC
        $exists = $this->npcSpawnModel
            ->where('cell_number', $cell['cell_number'])
            ->where('status', 'alive')
            ->countAllResults();

        if ($exists > 0) {
            return;
        }

        // Масштабирование только показателя здоровья:
        $finalHealth = round($npc['health'] * (1.0 + ($yarus - 1) * 0.2), 2);

        // Остальные характеристики остаются такими, как заданы в таблице npcs
        $data = [
            'npc_id'         => (int)$npc['id'],
            'cell_number'    => (int)$cell['cell_number'],
            'coordinate_x'   => (int)$cell['coordinate_x'],
            'coordinate_y'   => (int)$cell['coordinate_y'],
            'current_health' => $finalHealth, // единственный изменяемый параметр при спавне
            // Остальные статические параметры копируются без изменений:
            'level'          => $npc['level'],
            'strength'       => $npc['strength'],
            'agility'        => $npc['agility'],
            'intellect'      => $npc['intellect'],
            'damage_value'   => $npc['damage_value'],
            'armor'          => $npc['armor'],
            'spawned_at'     => date('Y-m-d H:i:s'),
            'status'         => 'alive'
        ];

        $this->npcSpawnModel->insert($data);
    }
}
