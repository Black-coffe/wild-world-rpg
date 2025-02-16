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
            $logger,
            $this->characterModel,
            $this->npcSpawnModel,
            new NpcModel(),
            new MapModel()
        );
    }

    public function run()
    {
        log_message('debug', "\u{1F504} Запуск AutoPveHandler...");

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
                log_message('debug', "✅ Нет живых NPC в клетке игрока ID={$playerId}.");
                continue;
            }

            // Для каждого NPC запускаем бой
            foreach ($npcsInCell as $npcSpawn) {
                if (!isset($npcSpawn['id']) || !is_numeric($npcSpawn['id'])) {
                    continue;
                }
                $npcSpawnId = (int) $npcSpawn['id'];

                log_message('debug', "\u{1F680} Запуск PvE боя: PlayerID={$playerId}, NPC SpawnID={$npcSpawnId}");
                $this->startNpcCombat($playerId, $npcSpawnId);
                $handledCount++;
            }
        }

        log_message('debug', "⚔️ Всего обработано PvE боёв: {$handledCount}");
    }

    protected function startNpcCombat(int $playerId, int $npcSpawnId): void
    {
        log_message('debug', "🎯 Начинаем бой: PlayerID={$playerId}, NPC SpawnID={$npcSpawnId}");

        // Получаем данные игрока (вместе с telegram_id для уведомления)
        $playerData = $this->characterModel
            ->select('characters.*, telegram_users.telegram_id as telegram_chat_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.id', $playerId)
            ->first();

        if (!$playerData) {
            log_message('error', "⛔ Ошибка: Персонаж с ID {$playerId} не найден!");
            return;
        }

        // Получаем данные NPC (конкретный spawn)
        $npcData = $this->npcSpawnModel->find($npcSpawnId);
        if (!$npcData) {
            log_message('error', "⛔ Ошибка: NPC спавн ID {$npcSpawnId} не найден!");
            return;
        }

        // Проверяем, что игрок и NPC действительно в одной клетке
        if ($playerData['cell_number'] !== $npcData['cell_number']) {
            log_message('warning', "⚠️ Игрок ID={$playerId} и NPC ID={$npcSpawnId} в разных клетках! Бой не начинается.");
            return;
        }

        // Находим информацию о самом NPC (из таблицы npcs)
        $npcModel = new NpcModel();
        $npcInfo  = $npcModel->find($npcData['npc_id']);
        if (!$npcInfo) {
            log_message('error', "⛔ Ошибка: NPC ID {$npcData['npc_id']} не найден в npcs!");
            return;
        }
        $npcData['name'] = $npcInfo['npc_name_ru'] ?? 'Неизвестный враг';

        // Запускаем бой
        $biome = "Grasslands";
        $fightResult = $this->pveService->attack($playerData, $npcData, $biome);

        log_message('debug', "🏆 PvE Бой завершён. Итоги: " . json_encode($fightResult));

        // Проверяем корректность результата
        if (!isset($fightResult['player']) || !is_array($fightResult['player'])) {
            log_message('error', "⛔ Ошибка: PvE бой завершён, но нет данных о игроке (массив)!");
            return;
        }
        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "⛔ Ошибка: PvE бой завершён, но победитель не является объектом!");
            return;
        }

        $updatedPlayer = $fightResult['player'];
        $winner = $fightResult['winner'];
        // Защищённо получаем данные проигравшего
        $loser = $fightResult['loser'] ?? null;
        $loserName = (is_object($loser) && isset($loser->name)) ? $loser->name : 'Неизвестный';

        log_message('debug', "🏆 Победитель: {$winner->name} | Проигравший: {$loserName}");

        // Если победил игрок — удаляем NPC
        if ($winner->name === $playerData['name']) {
            sleep(2); // для наглядности лога

            log_message('debug', "✅ Игрок победил. Начинаем удаление NPC Spawn ID={$npcSpawnId}...");

            // 1. Просмотр записи перед удалением
            $existsBeforeDelete = $this->npcSpawnModel->find($npcSpawnId);
            log_message('debug', "🔎 NPC перед удалением: " . json_encode($existsBeforeDelete));

            // 2. Обновляем статус в базе (необязательно)
            log_message('debug', "📝 Ставим status='dead'...");
            $this->npcSpawnModel->update($npcSpawnId, ['status' => 'dead']);
            log_message('debug', "📝 update() -> error: " . json_encode($this->npcSpawnModel->db->error()));

            // 3. Пытаемся удалить через метод delete() модели
            log_message('debug', "🗑️ Попытка delete({$npcSpawnId}) через модель...");
            // Если soft delete включен, используйте delete($npcSpawnId, true)
            $deleteResult = $this->npcSpawnModel->delete($npcSpawnId);
            log_message('debug', "🗑️ Результат delete() моделью: " . json_encode($deleteResult));
            log_message('debug', "🗑️ db->error() после delete(): " . json_encode($this->npcSpawnModel->db->error()));
            log_message('debug', "🗑️ db->affectedRows() после delete(): " . $this->npcSpawnModel->db->affectedRows());

            // 4. Дополнительное удаление через сырой SQL (на случай soft delete)
            log_message('debug', "🔥 Принудительный raw SQL: DELETE FROM npc_spawns WHERE id=?");
            $this->npcSpawnModel->db->query("DELETE FROM npc_spawns WHERE id = ?", [$npcSpawnId]);
            log_message('debug', "🔥 db->error(): " . json_encode($this->npcSpawnModel->db->error()));
            log_message('debug', "🔥 db->affectedRows(): " . $this->npcSpawnModel->db->affectedRows());

            // 5. Проверяем, осталась ли запись
            $existsAfterDelete = $this->npcSpawnModel->find($npcSpawnId);
            log_message('debug', "🔎 NPC после удаления: " . json_encode($existsAfterDelete));

            if ($existsAfterDelete === null) {
                log_message('info', "✅ NPC Spawn ID={$npcSpawnId} успешно удален.");
            } else {
                log_message('error', "❌ Ошибка: NPC Spawn ID={$npcSpawnId} всё ещё в базе данных!");
            }
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

        log_message('info', "✅ Обновлены данные игрока: ID={$playerId}, Здоровье={$updatedPlayer['health']}, Выносливость={$newTired}");
    }

}
