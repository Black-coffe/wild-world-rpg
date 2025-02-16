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
        log_message('debug', "Запуск PvE боя: PlayerID={$playerId}, NPC SpawnID={$npcSpawnId}");

        $playerData = $this->characterModel
            ->select('characters.*, telegram_users.telegram_id as telegram_chat_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $playerId)
            ->first();

        if (!$playerData) {
            log_message('error', "Ошибка: Персонаж с ID {$playerId} не найден!");
            return;
        }

        $npcData = $this->npcSpawnModel->find($npcSpawnId);

        if (!$npcData) {
            log_message('error', "Ошибка: NPC спавн ID {$npcSpawnId} не найден!");
            return;
        }

        $npcModel = new \App\Models\NpcModel();
        $npcInfo = $npcModel->find($npcData['npc_id']);

        if (!$npcInfo) {
            log_message('error', "Ошибка: NPC ID {$npcData['npc_id']} не найден в npcs!");
            return;
        }

        $npcData['name'] = $npcInfo['npc_name_ru'] ?? 'Неизвестный враг';

        $biome = "Grasslands";
        $fightResult = $this->pveService->attack($playerData, $npcData, $biome);

        // Логируем результат боя
        log_message('debug', "PvE Бой завершён. Итоги: " . json_encode($fightResult));

        if (!isset($fightResult['player']) || !is_object($fightResult['player'])) {
            log_message('error', "Ошибка: PvE бой завершён, но нет данных о игроке!");
            return;
        }

        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: PvE бой завершён, но победитель не является объектом!");
            return;
        }

        $updatedPlayer = $fightResult['player'];
        $winner = $fightResult['winner'];

        $newTired = max(1, rand(1, (int) floor($updatedPlayer->health)));

        $this->characterModel
            ->set('health', max(1, $updatedPlayer->health))
            ->set('tired', $newTired)
            ->set('experience', 'experience + ' . ($fightResult['rewards']['exp'] ?? 0), false)
            ->set('gold', 'gold + ' . ($fightResult['rewards']['gold'] ?? 0), false)
            ->where('id', $playerId)
            ->update();

        log_message('info', "Обновлены данные игрока: ID={$playerId}, Здоровье={$updatedPlayer->health}, Выносливость={$newTired}");

        if (!empty($fightResult['winner']) && is_object($fightResult['winner']) && $fightResult['winner']->name === $playerData['name']) {
            $this->npcSpawnModel->update($npcSpawnId, ['status' => 'dead']);
            log_message('info', "NPC ID={$npcSpawnId} помечен как 'dead'");
        }
    }

}
