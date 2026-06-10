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
 *
 * Анти-grind гейты (лог-ревью 2026-06-10, чар 994: 926 боёв/день от легаси
 * L15-агрессора, HP floor 1 → вечный цикл + спам уведомлений):
 *  • skip_below_health — игрок на полу HP (уже побеждён) не атакуется повторно;
 *  • pair_cooldown — та же пара (игрок, спавн) не дерётся чаще раза в N минут.
 * Оба tunable в админке (combat.auto_pve.*); 0 = соответствующий гейт выключен.
 */
class AutoPveHandler
{
    protected CharacterModel $characterModel;
    protected NpcSpawnModel $npcSpawnModel;
    protected MapModel $mapModel;
    protected PvEService $pveService;

    /** Memo на один run() — не дёргать game_settings на каждую пару. */
    protected ?int $pairCooldownMinutes = null;

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

        $skipBelowHealth = $this->skipBelowHealth();

        foreach ($players as $player) {
            if (!isset($player['id'], $player['cell_number']) || !is_numeric($player['id'])) {
                continue;
            }
            $playerId = (int) $player['id'];

            // Анти-grind гейт №1: игрок на полу HP уже проиграл (бой floor'ит health
            // в 1) — повторный авто-бой каждую минуту = вечный цикл без выхода.
            $healthRaw = $player['health'] ?? null;
            $health    = is_numeric($healthRaw) ? (float) $healthRaw : 0.0;
            if (self::shouldSkipByHealth($health, $skipBelowHealth)) {
                continue;
            }

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

                // Анти-grind гейт №2: та же пара (игрок, спавн) — не чаще раза в N минут.
                if ($this->isPairOnCooldown($playerId, $npcSpawnId)) {
                    continue;
                }

                $this->startNpcCombat($playerId, $npcSpawnId);
                $handledCount++;
            }
        }
    }

    /**
     * Анти-grind: пропустить игрока, если его health ≤ порога (игрок уже на полу
     * после проигрыша — добивать бессмысленно). Порог 0 = гейт выключен.
     */
    public static function shouldSkipByHealth(float $health, float $threshold): bool
    {
        return $threshold > 0 && $health <= $threshold;
    }

    /** Cache-ключ кулдауна пары (игрок, спавн). */
    public static function pairCooldownKey(int $playerId, int $npcSpawnId): string
    {
        return "autopve_pair_{$playerId}_{$npcSpawnId}";
    }

    /** Пара недавно дралась (cache-ключ жив)? При cooldown=0 гейт выключен. */
    protected function isPairOnCooldown(int $playerId, int $npcSpawnId): bool
    {
        if ($this->pairCooldownMinutes() <= 0) {
            return false;
        }

        return cache()->get(self::pairCooldownKey($playerId, $npcSpawnId)) !== null;
    }

    /** Запомнить бой пары (TTL = кулдаун). Ставится ПЕРЕД боем — см. startNpcCombat. */
    protected function rememberPairFight(int $playerId, int $npcSpawnId): void
    {
        $minutes = $this->pairCooldownMinutes();
        if ($minutes <= 0) {
            return;
        }
        cache()->save(self::pairCooldownKey($playerId, $npcSpawnId), 1, $minutes * 60);
    }

    /** combat.auto_pve.pair_cooldown_minutes (default 15; 0 = гейт выключен). */
    protected function pairCooldownMinutes(): int
    {
        if ($this->pairCooldownMinutes === null) {
            $v = (new \App\Services\GameSettings\GameSettingsService())
                ->get('combat.auto_pve.pair_cooldown_minutes', 15);
            $this->pairCooldownMinutes = is_numeric($v) ? max(0, (int) $v) : 15;
        }

        return $this->pairCooldownMinutes;
    }

    /**
     * combat.auto_pve.skip_below_health (default 5; 0 = гейт выключен).
     * Default 5, НЕ 1: HealthRegenerationHandler регенит +0.05/мин (level<20) —
     * при пороге 1 лежачий (HP floor = 1) уже через минуту выше порога и снова в бою.
     */
    protected function skipBelowHealth(): float
    {
        $v = (new \App\Services\GameSettings\GameSettingsService())
            ->get('combat.auto_pve.skip_below_health', 5);

        return is_numeric($v) ? max(0.0, (float) $v) : 5.0;
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

        // Проверяем, что игрок и NPC действительно в одной клетке. Сравниваем как int:
        // CharacterModel возвращает CharacterEntity (cell_number — int), спавн из модели — string;
        // строгое !== давало ложное «разные клетки» (int !== string) → авто-бой не запускался → npc_kills не рос.
        $pCellRaw = $playerData['cell_number'] ?? null;
        $sCellRaw = $npcData['cell_number'] ?? null;
        $pCell    = is_numeric($pCellRaw) ? (int) $pCellRaw : -1;
        $sCell    = is_numeric($sCellRaw) ? (int) $sCellRaw : -2;
        if ($pCell !== $sCell) {
            return;
        }

        // Находим информацию о самом NPC (из таблицы npcs)
        $npcModel = new NpcModel();
        $npcInfo  = $npcModel->find($npcData['npc_id']);
        if (!$npcInfo) {
            return;
        }

        // ADR-089: нейтральный NPC (ai_behavior='passive') НЕ атакует первым — авто-бой пропускаем.
        // С ним игрок взаимодействует через экран встречи (NpcEncounterAction).
        if (is_array($npcInfo) && ($npcInfo['ai_behavior'] ?? null) === 'passive') {
            return;
        }

        $npcData['name'] = $npcInfo['npc_name_ru'] ?? 'Неизвестный враг';

        // Анти-grind: кулдаун ставим ПЕРЕД боем (после всех валидаций) — даже если
        // бой/уведомление упадёт, пара не будет молотиться каждую минуту крона.
        $this->rememberPairFight($playerId, $npcSpawnId);

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
