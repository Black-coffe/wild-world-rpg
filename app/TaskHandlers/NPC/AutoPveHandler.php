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


/**
 * Класс AutoPveHandler вызывается CRON-ом каждую минуту.
 * Логика:
 *  1) Перебираем всех игроков
 *  2) Ищем "живых" NPC в той же клетке
 *  3) Запускаем бой (PVE)
 *  4) Если игрок победил, пытаемся удалить запись из npc_spawns
 */
class AutoPveHandler
{
    protected CharacterModel $characterModel;
    protected NpcSpawnModel $npcSpawnModel;
    protected MapModel $mapModel;
    protected PvEService $pveService;

    public function __construct()
    {
        // Модели
        $this->characterModel = new CharacterModel();
        $this->npcSpawnModel  = new NpcSpawnModel();
        $this->mapModel       = new MapModel();

        // Сервисы
        $logger        = new NullLogger();
        $damageService = new DamageService($logger);
        $effectService = new EffectService($logger);
        $battleLogger  = new BattleLogger($logger);
        $battleService = new BattleService($damageService, $effectService, $battleLogger, $logger);
        $rewardService = new RewardService($logger);
        $equipmentService = new EquipmentService($logger);

        $this->pveService = new PvEService(
            $battleService,
            $rewardService,
            $equipmentService,
            $this->characterModel,
            $this->npcSpawnModel,
            new NpcModel()
        );
    }

    public function run()
    {
        $players = $this->characterModel->findAll();
        $handledCount = 0;

        foreach ($players as $player) {
            if (!isset($player['id'], $player['cell_number']) || !is_numeric($player['id'])) {
                continue;
            }
            $playerId = (int) $player['id'];

            // Ищем NPC со статусом alive в той же клетке
            $npcsInCell = $this->npcSpawnModel
                ->where('cell_number', $player['cell_number'])
                ->where('status', 'alive')
                ->findAll();

            if (empty($npcsInCell)) {
                continue;
            }

            // Для каждого NPC запускаем бой
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
        // Получаем данные игрока (вместе с telegram_id для уведомления)
        $playerData = $this->characterModel
            ->select('characters.*, telegram_users.telegram_id as telegram_chat_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $playerId)
            ->first();

        if (!$playerData) {
            return;
        }

        // Получаем данные NPC (конкретный spawn)
        $npcData = $this->npcSpawnModel->find($npcSpawnId);
        if (!$npcData) {
            return;
        }

        // Проверяем, что игрок и NPC действительно в одной клетке
        if ($playerData['cell_number'] !== $npcData['cell_number']) {
            return;
        }

        // Находим информацию о самом NPC (из таблицы npcs)
        $npcModel = new NpcModel();
        $npcInfo  = $npcModel->find($npcData['npc_id']);
        if (!$npcInfo) {
            return;
        }
        $npcData['name'] = $npcInfo['npc_name_ru'] ?? 'Неизвестный враг';

        // Запускаем бой
        $biome = "Grasslands";
        $fightResult = $this->pveService->attack($playerData, $npcData, $biome);

        // Проверяем корректность результата
        if (!isset($fightResult['player']) || !is_array($fightResult['player'])) {
            return;
        }
        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            return;
        }

        $updatedPlayer = $fightResult['player'];
        $winner = $fightResult['winner'];

        // Если победил игрок — удаляем NPC
        if ($winner->name === $playerData['name']) {
            // 2. Обновляем статус в базе (необязательно)
            $this->npcSpawnModel->update($npcSpawnId, ['status' => 'dead']);

            // 3. Пытаемся удалить через метод delete() модели
            // Если soft delete включен, используйте delete($npcSpawnId, true)
            $deleteResult = $this->npcSpawnModel->delete($npcSpawnId);

            // 4. Дополнительное удаление через сырой SQL (на случай soft delete)
            $this->npcSpawnModel->db->query("DELETE FROM npc_spawns WHERE id = ?", [$npcSpawnId]);
        }

        // Обновляем характеристики игрока
        $newTired = max(1, rand(1, (int) floor($updatedPlayer['health'])));
        $this->characterModel
            ->set('health', max(1, $updatedPlayer['health']))
            ->set('tired', $newTired)
            ->set('experience', 'experience + ' . ($fightResult['rewards']['exp'] ?? 0), false)
            ->set('gold', 'gold + ' . ($fightResult['rewards']['gold'] ?? 0), false)
            ->where('id', $playerId)
            ->update();
    }

}
