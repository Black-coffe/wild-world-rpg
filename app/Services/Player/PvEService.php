<?php

namespace App\Services\Player;

use App\Services\PVE\BattleService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\PveMessageFormatter;
use App\Services\PVE\PveCombatValidator;
use App\Services\PVE\PveBattleLogWriter;
use App\Services\PVE\PveNotificationSender;
use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Models\NpcModel;
use App\Entities\BattleCharacter;

class PvEService
{
    private BattleService $battleService;
    private RewardService $rewardService;
    private EquipmentService $equipmentService;
    private CharacterModel $characterModel;
    private PveMessageFormatter $messageFormatter;
    private PveCombatValidator $combatValidator;
    private PveBattleLogWriter $battleLogWriter;
    private PveNotificationSender $notificationSender;

    public function __construct(
        BattleService $battleService,
        RewardService $rewardService,
        EquipmentService $equipmentService,
        CharacterModel $characterModel,
        NpcSpawnModel $npcSpawnModel,
        NpcModel $npcModel,
        ?PveMessageFormatter $messageFormatter = null,
        ?PveCombatValidator $combatValidator = null,
        ?PveBattleLogWriter $battleLogWriter = null,
        ?PveNotificationSender $notificationSender = null
    ) {
        $this->battleService      = $battleService;
        $this->rewardService      = $rewardService;
        $this->equipmentService   = $equipmentService;
        $this->characterModel     = $characterModel;
        $this->messageFormatter   = $messageFormatter ?? new PveMessageFormatter();
        $this->combatValidator    = $combatValidator ?? new PveCombatValidator($npcSpawnModel, $npcModel);
        $this->battleLogWriter    = $battleLogWriter ?? new PveBattleLogWriter();
        $this->notificationSender = $notificationSender ?? new PveNotificationSender();
    }

    /**
     * Выполняет PvE бой между персонажем и NPC.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $playerData Данные персонажа.
     * @param array<string,mixed> $npcData    Данные NPC з npc_spawns.
     * @param string $biome     Текущий биом.
     * @return array<string,mixed> Итог боя.
     */
    public function attack(array|\App\Entities\CharacterEntity $playerData, array $npcData, string $biome): array
    {
        // Путь встречи (NpcInteractionService::fight) передаёт CharacterEntity; нормализуем в массив —
        // ниже new BattleCharacter() и offset-доступ требуют array (auto-PvE всегда даёт массив).
        // toArray(), НЕ (array)-каст: каст Entity ломает ключи (фидбэк ADR-092).
        if ($playerData instanceof \App\Entities\CharacterEntity) {
            $playerData = $playerData->toArray();
        }

        // npc_id может отсутствовать на входе: путь встречи (NpcInteractionService::fight) передаёт
        // ['id' => spawnId] — полные данные грузит validateAndLoadNpc ниже. Не обращаемся к npc_id напрямую
        // (иначе Undefined array key → бой во встрече падал до начала → npc_kills не рос).
        $npcRefRaw = $npcData['npc_id'] ?? $npcData['id'] ?? '?';
        $npcRef    = is_scalar($npcRefRaw) ? (string) $npcRefRaw : '?';
        $pNameRaw  = $playerData['name'] ?? '?';
        $pName     = is_scalar($pNameRaw) ? (string) $pNameRaw : '?';
        log_message('debug', "Атака: Игрок {$pName} против NPC ID={$npcRef}");

        // Validate + load NPC merged data (Step 2 v0.51.87)
        $validation = $this->combatValidator->validateAndLoadNpc($playerData, $npcData);
        if (!$validation['ok']) {
            return $validation['response'];
        }
        $npcData = $validation['npcData'];

        // Оборачиваем данные игрока и NPC в объекты BattleCharacter
        $player = new BattleCharacter($playerData);
        $npc = new BattleCharacter($npcData);

        // Применяем бонусы от экипировки к игроку
        $this->equipmentService->applyEquipmentBonuses($player);

        // Запускаем бой через BattleService
        $fightResult = $this->battleService->startFight($player, $npc, $biome);
        log_message('debug', "Результаты боя: " . json_encode($fightResult));

        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: Победитель боя — строка, а не объект!");
            return ['message' => "Ошибка в логике боя."];
        }

        // Подробное логирование боя через PveBattleLogWriter (Step 3 v0.51.88)
        $this->battleLogWriter->write($playerData, $npcData, $fightResult);

        // Выдача наград через RewardService
        $rewards = $this->rewardService->grantRewards($fightResult['winner'], $fightResult['loser']);
        log_message('info', "Игрок {$player->name} получил: +{$rewards['exp']} опыта, +{$rewards['gold']} золота");

        // ADR-088 Фаза 2: победа игрока над NPC → +1 к счётчику (квесты objective_type=npc_kills).
        // Гейт по winner==player; счётчик ведём всегда (квест-прогрессия гейтится killswitch в handler).
        $winner = $fightResult['winner'];
        if ($winner instanceof BattleCharacter && (int) $winner->id === (int) $player->id) {
            $this->characterModel->incrementNpcKills((int) $player->id);
        }

        // Обновляем данные игрока
        $this->characterModel->update($player->id, [
            'health'     => max(1, $player->health),
            'tired'      => max(1, rand(1, (int)floor($player->health))),
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);
        $updatedPlayerData = $this->characterModel->find($player->id);

        // Формируем итоговое сообщение для Telegram
        $mapLocation = [
            'coordinate_x' => $player->cell_number % 1000,
            'coordinate_y' => floor($player->cell_number / 1000),
        ];
        $finalText = $this->messageFormatter->buildFightResultMessage(
            $updatedPlayerData,
            $npcData['name'],
            $fightResult,
            $mapLocation,
            $rewards
        );
        // Уведомление НЕ должно ронять уже завершённый бой (награды + npc_kills уже применены выше).
        // В пути встречи Telegram-бот может быть не инициализирован в момент вызова → ловим и логируем.
        try {
            $this->notificationSender->send($updatedPlayerData, $finalText);
        } catch (\Throwable $e) {
            log_message('warning', 'PvE notify failed (бой уже засчитан): ' . $e->getMessage());
        }

        return [
            'message' => "Бой завершён! Победитель: " . ($fightResult['winner']->name ?? "Ничья"),
            'rewards' => $rewards,
            'log'     => $fightResult['log'],
            'winner'  => $fightResult['winner'],
            'player'  => $updatedPlayerData,
        ];
    }

}
