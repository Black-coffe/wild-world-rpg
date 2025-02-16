<?php

namespace App\TaskHandlers\NPC;

use App\Services\PVE\DamageService;
use App\Services\PVE\EffectService;
use App\Services\PVE\BattleLogger;
use App\Services\PVE\BattleService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Services\Player\PvEService;
use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use App\Models\NpcModel;
use Psr\Log\NullLogger;

class AutoPveHandler
{
    protected CharacterModel $characterModel;
    protected NpcSpawnModel $npcSpawnModel;
    protected MapModel $mapModel; // ✅ Добавляем MapModel
    protected PvEService $pveService;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->npcSpawnModel = new NpcSpawnModel();
        $this->mapModel = new MapModel(); // ✅ Создаём экземпляр MapModel

        $logger = new NullLogger();
        $damageService = new DamageService($logger);
        $effectService = new EffectService($logger);
        $battleLogger = new BattleLogger($logger);
        $battleService = new BattleService($damageService, $effectService, $battleLogger, $logger);
        $rewardService = new RewardService($logger);
        $equipmentService = new EquipmentService($logger);

        // ✅ Теперь передаём 7 аргументов, включая `MapModel`
        $this->pveService = new PvEService(
            $battleService,
            $rewardService,
            $equipmentService,
            $logger,
            $this->characterModel,
            $this->npcSpawnModel,
            new NpcModel(),
            new MapModel()
        );
    }

    /**
     * Метод, который запускается CRON'ом раз в минуту.
     */
    public function run()
    {
        $players = $this->characterModel->findAll();
        $handledCount = 0;

        foreach ($players as $player) {
            if (!isset($player['id']) || !isset($player['cell_number']) || !is_numeric($player['id'])) {
                continue;
            }

            $playerId = (int) $player['id'];

            $npcsInCell = $this->npcSpawnModel
                ->where('cell_number', $player['cell_number'])
                ->where('status', 'alive')
                ->findAll();

            if (empty($npcsInCell)) {
                continue;
            }

            foreach ($npcsInCell as $npcSpawn) {
                if (!isset($npcSpawn['id']) || !is_numeric($npcSpawn['id'])) {
                    continue;
                }

                $npcSpawnId = (int) $npcSpawn['id'];

                $this->startNpcCombat($playerId, $npcSpawnId);
                $handledCount++;
            }
        }
    }

    protected function startNpcCombat(int $playerId, int $npcSpawnId): void
    {
        $playerData = $this->characterModel
            ->select('characters.*, telegram_users.telegram_id as telegram_chat_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $playerId)
            ->first();
        if (!$playerData) {
            return;
        }

        $npcData = $this->npcSpawnModel->find($npcSpawnId);
        if (!$npcData) {
            return;
        }

        $biome = "Grasslands";
        $fightResult = $this->pveService->attack($playerData, $npcData, $biome);

        $this->characterModel->update($playerId, [
            'health' => max(1, $playerData['health']),
            'tired' => max(1, rand(1, (int) floor($playerData['health']))),
            'experience' => $playerData['experience'] + ($fightResult['rewards']['exp'] ?? 0),
            'gold' => $playerData['gold'] + ($fightResult['rewards']['gold'] ?? 0)
        ]);

        if (!empty($fightResult['winner']) && $fightResult['winner'] === $playerData['name']) {
            $this->npcSpawnModel->update($npcSpawnId, ['status' => 'dead']);
        }
    }
}
