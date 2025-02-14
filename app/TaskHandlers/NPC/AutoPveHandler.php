<?php

namespace App\TaskHandlers\NPC;

use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Services\Player\PvEService;
use CodeIgniter\CLI\CLI;

class AutoPveHandler
{
    /** @var CharacterModel */
    protected $characterModel;

    /** @var NpcSpawnModel */
    protected $npcSpawnModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->npcSpawnModel  = new NpcSpawnModel();
    }

    /**
     * Метод, который запускается CRON'ом раз в минуту.
     */
    public function run()
    {
        CLI::write("=== AutoPveHandler started ===", 'yellow');

        // Получаем всех персонажей
        $players = $this->characterModel->findAll();
        $handledCount = 0;

        // Перебираем каждого игрока
        foreach ($players as $player) {
            if (empty($player['cell_number'])) {
                continue;
            }

            // Находим NPC, которые находятся в той же ячейке и имеют статус 'alive'
            $npcsInCell = $this->npcSpawnModel
                ->where('cell_number', $player['cell_number'])
                ->where('status', 'alive')
                ->findAll();

            if (empty($npcsInCell)) {
                continue;
            }

            // Для каждого такого NPC проверяем, совпадают ли ячейки (дополнительная проверка) и вызываем PvE-сервис
            foreach ($npcsInCell as $npcSpawn) {
                if ($player['cell_number'] == $npcSpawn['cell_number']) {
                    $this->startNpcCombat($player['id'], $npcSpawn['id']);
                    $handledCount++;
                }
            }
        }

        CLI::write("Handled PvE fights: {$handledCount}.", 'green');
        CLI::write("=== AutoPveHandler finished ===", 'yellow');
    }

    /**
     * Запуск боя PvE между игроком и конкретным NPC.
     *
     * @param int $playerId
     * @param int $npcSpawnId
     */
    protected function startNpcCombat(int $playerId, int $npcSpawnId): void
    {
        CLI::write("Starting PvE: Player #{$playerId} vs NPC #{$npcSpawnId}", 'blue');

        // Создаем экземпляр нашего PvE-сервиса и запускаем бой
        $pveService = new PvEService();
        $pveService->attack($playerId, $npcSpawnId);
    }
}
