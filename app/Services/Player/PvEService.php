<?php

namespace App\Services\Player;

use App\Services\PVE\BattleService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\LootTableService;
use App\Services\PVE\PveMessageFormatter;
use App\Services\PVE\PveCombatValidator;
use App\Services\PVE\PveBattleLogWriter;
use App\Services\PVE\PveNotificationSender;
use App\Models\CharacterModel;
use App\Models\NpcSpawnModel;
use App\Models\NpcModel;
use App\Entities\BattleCharacter;
use App\Services\Food\FoodBuffService;

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
    private LootTableService $lootTableService;
    private FoodBuffService $foodBuffService;

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
        ?PveNotificationSender $notificationSender = null,
        ?LootTableService $lootTableService = null,
        ?FoodBuffService $foodBuffService = null
    ) {
        $this->battleService      = $battleService;
        $this->rewardService      = $rewardService;
        $this->equipmentService   = $equipmentService;
        $this->characterModel     = $characterModel;
        $this->messageFormatter   = $messageFormatter ?? new PveMessageFormatter();
        $this->combatValidator    = $combatValidator ?? new PveCombatValidator($npcSpawnModel, $npcModel);
        $this->battleLogWriter    = $battleLogWriter ?? new PveBattleLogWriter();
        $this->notificationSender = $notificationSender ?? new PveNotificationSender();
        $this->lootTableService   = $lootTableService ?? new LootTableService();
        $this->foodBuffService    = $foodBuffService ?? new FoodBuffService();
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

        // E21 Ф1 (ADR-121): боевой food-баф «Сытость». Пока сыт (characters.well_fed_until),
        // игрок в PvE бьёт сильнее и получает меньше урона → блюда (V8/W23) становятся
        // охотничьим ресурсом, дом-цикл кормит охоту (E15)/север (E16). Множители default 1.0
        // (killswitch OFF или не сыт) → бой byte-identical. NPC баф не получает (PvE-only).
        $wellFedUntil = $playerData['well_fed_until'] ?? null;
        $player->outgoingDamageMultiplier = $this->foodBuffService->combatDamageMultiplierFor($wellFedUntil);
        $player->incomingDamageMultiplier = $this->foodBuffService->incomingDamageMultiplierFor($wellFedUntil);

        // Запускаем бой через BattleService
        $fightResult = $this->battleService->startFight($player, $npc, $biome);
        log_message('debug', "Результаты боя: " . json_encode($fightResult));

        if (!isset($fightResult['winner']) || !is_object($fightResult['winner'])) {
            log_message('error', "Ошибка: Победитель боя — строка, а не объект!");
            return ['message' => "Ошибка в логике боя."];
        }

        // Подробное логирование боя через PveBattleLogWriter (Step 3 v0.51.88)
        $this->battleLogWriter->write($playerData, $npcData, $fightResult);

        // Награды выдаёт ТОЛЬКО победивший ИГРОК. Если победил NPC (игрок проиграл),
        // grantRewards писал бы по winner->id = id NPC: adjustGold/update/insert в
        // character_resources с id, которого нет в characters → FK fail каждую минуту
        // на застрявших AFK-игроках у агрессивных NPC (AutoPveHandler-крон). Гейт по
        // winner==player. Бонус: проигравший больше не получает exp/gold победителя
        // (раньше $rewards победителя-NPC ошибочно начислялись игроку ниже).
        $winner    = $fightResult['winner'];
        $playerWon = self::playerIsWinner($winner, (int) $player->id);

        $rewards = $playerWon
            ? $this->rewardService->grantRewards($winner, $fightResult['loser'])
            : ['exp' => 0, 'gold' => 0, 'strength' => 0, 'agility' => 0, 'intellect' => 0, 'resource' => null, 'craftedItem' => null];
        log_message('info', "Игрок {$player->name} получил: +{$rewards['exp']} опыта, +{$rewards['gold']} золота");

        // ADR-088 Фаза 2: победа игрока над NPC → +1 к счётчику (квесты objective_type=npc_kills).
        if ($playerWon) {
            $this->characterModel->incrementNpcKills((int) $player->id);
            // ADR-106: победа над уникальным боссом → маркер в action_log (боссо-достижения).
            $this->logBossKill($playerData, $npcData);
        }

        // Обновляем данные игрока
        $this->characterModel->update($player->id, [
            'health'     => max(1, $player->health),
            'tired'      => max(1, rand(1, (int)floor($player->health))),
            'experience' => $player->experience + ($rewards['exp'] ?? 0),
            'gold'       => $player->gold + ($rewards['gold'] ?? 0),
        ]);

        // ADR-107: лут-таблица побеждённого NPC (npcs.loot_table_id; killswitch внутри).
        // 🔴 СТРОГО ПОСЛЕ update выше: тот пишет gold АБСОЛЮТНЫМ значением (снапшот
        // до боя + стандартное золото) и затёр бы атомарный adjustGold трофея
        // (Tier-3 catch 2026-06-10: дельта золота < минимума трофея). Defensive:
        // трофеи не роняют уже засчитанный бой.
        if ($playerWon) {
            $lootRaw = $npcData['loot_table_id'] ?? null;
            if (is_numeric($lootRaw)) {
                try {
                    $rewards['trophies'] = $this->lootTableService->rollAndGrant((int) $lootRaw, (int) $player->id);
                } catch (\Throwable $e) {
                    log_message('warning', 'LootTable roll failed (бой уже засчитан): ' . $e->getMessage());
                }
            }
        }

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

    /**
     * Победитель боя — это ИГРОК? Чистый предикат (вынесен для тестируемости гейта наград).
     *
     * Награду выдаём только победившему игроку: если победил NPC, grantRewards писал бы по
     * winner->id = id NPC (нет в characters) → FK fail на character_resources (AutoPveHandler-крон,
     * застрявшие AFK-игроки у агрессивных NPC). См. PvEServiceRewardGateTest.
     */
    public static function playerIsWinner(mixed $winner, int $playerId): bool
    {
        return $winner instanceof BattleCharacter && (int) $winner->id === $playerId;
    }

    /**
     * ADR-106: имя action_log-маркера победы над боссом, или null если NPC не босс.
     * npcData — merged выход PveCombatValidator (npcs + npc_spawns), is_boss/npc_name_en там.
     * Чистый предикат (unit-тестируется).
     *
     * @param array<string,mixed> $npcData
     */
    public static function bossKillFlagName(array $npcData): ?string
    {
        if (empty($npcData['is_boss'])) {
            return null;
        }
        $en = $npcData['npc_name_en'] ?? null;

        return is_string($en) && $en !== '' ? 'BOSS_KILL_' . $en : null;
    }

    /**
     * ADR-106: записать маркер победы над боссом (каждое убийство = строка — заодно история).
     * Defensive: ошибка лога НЕ роняет уже завершённый бой (награды применены выше).
     *
     * @param array<string,mixed> $playerData
     * @param array<string,mixed> $npcData
     */
    protected function logBossKill(array $playerData, array $npcData): void
    {
        $flag = self::bossKillFlagName($npcData);
        if ($flag === null) {
            return;
        }
        try {
            $charRaw = $playerData['id'] ?? null;
            $chatRaw = $playerData['telegram_chat_id'] ?? null; // путь авто-PvE даёт JOIN'ом; встреча — нет
            (new \App\Models\ActionLogModel())->insert([
                'character_id'  => is_numeric($charRaw) ? (int) $charRaw : 0,
                'chat_id'       => is_numeric($chatRaw) ? (int) $chatRaw : 0, // NOT NULL без default — 0 если неизвестен
                'action_name'   => $flag,
                'action_status' => 'Completed', // строго из enum (урок E3/m8k2b)
                'description'   => 'ADR-106: победа над боссом ' . (is_scalar($npcData['npc_name_ru'] ?? null) ? (string) $npcData['npc_name_ru'] : '?'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'BOSS_KILL лог не записан (бой уже засчитан): ' . $e->getMessage());
        }
    }

}
