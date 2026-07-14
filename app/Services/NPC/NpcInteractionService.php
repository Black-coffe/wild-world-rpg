<?php

declare(strict_types=1);

namespace App\Services\NPC;

use App\Models\CharacterModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\NpcDialogueModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Quest\QuestChainService;
use App\Services\PVE\BattleService;
use App\Services\PVE\BattleLogger;
use App\Services\PVE\DamageService;
use App\Services\PVE\EffectService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\RewardService;
use App\Services\Player\PvEService;
use Psr\Log\NullLogger;

/**
 * ADR-089 Фаза 1 — система встреч с нейтральными NPC.
 *
 * Нейтральный NPC = `npcs.ai_behavior='passive'` (не атакуется авто-боём — гейт в
 * AutoPveHandler). При встрече игрок выбирает действие. Бой переиспользует {@see PvEService}.
 * Тексты реплик — data-driven ({@see NpcDialogueModel}). Всё под killswitch
 * `npc.interaction_enabled` (dormant default).
 */
final class NpcInteractionService
{
    private GameSettingsService $settings;
    private NpcSpawnModel $spawns;
    private NpcModel $npcs;
    private NpcDialogueModel $dialogues;
    private CharacterModel $characters;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?NpcSpawnModel $spawns = null,
        ?NpcModel $npcs = null,
        ?NpcDialogueModel $dialogues = null,
        ?CharacterModel $characters = null
    ) {
        $this->settings   = $settings ?? new GameSettingsService();
        $this->spawns     = $spawns ?? new NpcSpawnModel();
        $this->npcs       = $npcs ?? new NpcModel();
        $this->dialogues  = $dialogues ?? new NpcDialogueModel();
        $this->characters = $characters ?? new CharacterModel();
    }

    /** Killswitch: встречи с нейтральными NPC активны. Default false → dormant. */
    public function enabled(): bool
    {
        return $this->boolSetting('npc.interaction_enabled', false);
    }

    /** ADR-089 Фаза 3: шанс встречи с нейтралом на клетке во время Похода (0..1). */
    public function marchEncounterChance(): float
    {
        $v = $this->settings->get('npc.march_encounter_chance', 0.0);

        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * ADR-089 Phase 6: killswitch диалого-центричной встречи (ветвящееся дерево
     * вместо меню 6 кнопок). Default false → dormant.
     */
    public function richDialogueEnabled(): bool
    {
        return $this->boolSetting('npc.rich_dialogue_enabled', false);
    }

    /**
     * ADR-089 Phase 6: побег NPC, который слабее игрока, при провокации. Default true.
     */
    public function fleeEnabled(): bool
    {
        return $this->boolSetting('npc.flee_enabled', true);
    }

    /**
     * ADR-089 Phase 6: слабее ли NPC игрока (для механики побега при провокации).
     * Детерминированно (без RNG): сравнение по уровню, тай-брейк по текущему HP NPC.
     */
    public function npcWeakerThanPlayer(int $playerId, int $npcId): bool
    {
        $player = $this->characters->find($playerId);
        $npc    = $this->npcs->find($npcId);
        if (! $player instanceof \App\Entities\CharacterEntity || ! is_array($npc)) {
            return false;
        }
        $pLvlRaw  = $player['level'] ?? null;
        $nLvlRaw  = $npc['level'] ?? null;
        $pLevel   = is_numeric($pLvlRaw) ? (int) $pLvlRaw : 0;
        $nLevel   = is_numeric($nLvlRaw) ? (int) $nLvlRaw : 0;

        return $nLevel < $pLevel;
    }

    /** ADR-089 Phase 6: NPC сбегает (провокация + слабее) — спавн исчезает. */
    public function npcFlees(int $spawnId): void
    {
        $this->consumeSpawn($spawnId);
    }

    /**
     * ADR-089 Фаза 3: есть ли у персонажа поход на паузе из-за встречи с NPC.
     * Используется encounter-экраном, чтобы предложить «🚜 Продолжить поход» вместо «🚶 Уйти».
     */
    public function pausedMarchExists(int $characterId): bool
    {
        $res = $this->spawns->db->table('character_tasks ct')
            ->select('ct.id')
            ->join('tasks t', 't.id = ct.task_id')
            ->where('ct.character_id', $characterId)
            ->where('ct.status', 'paused')
            ->where('t.name', 'Marching')
            ->get();
        if ($res === false) {
            return false;
        }

        return is_array($res->getRowArray());
    }

    /**
     * Живой НЕЙТРАЛЬНЫЙ (passive) спавн на клетке + данные шаблона npcs, либо null.
     *
     * @return array<int|string,mixed>|null  [spawn_id, npc_id, npc_name_ru, description, ...]
     */
    public function passiveSpawnOnCell(int $cellNumber): ?array
    {
        $row = $this->spawns
            ->select('npc_spawns.id AS spawn_id, npc_spawns.npc_id, npc_spawns.cell_number, '
                . 'npcs.npc_name_ru, npcs.npc_name_en, npcs.description, npcs.ai_behavior, npcs.faction_id')
            ->join('npcs', 'npcs.id = npc_spawns.npc_id')
            ->where('npc_spawns.cell_number', $cellNumber)
            ->where('npc_spawns.status', 'alive')
            ->where('npcs.ai_behavior', 'passive')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * ADR-101/ADR-089 — конкретный passive-NPC на клетке по npc_id. Нужен resident-picker'у
     * поселений: несколько жителей делят якорную клетку, а `passiveSpawnOnCell`→`first()` вернул
     * бы только одного (остальные деревья были недостижимы — BUILT-BUT-DEAD). Свежий Model на
     * вызов — анти builder-state quirk.
     *
     * @return array<int|string,mixed>|null
     */
    public function passiveSpawnOnCellForNpc(int $cellNumber, int $npcId): ?array
    {
        $row = (new NpcSpawnModel())
            ->select('npc_spawns.id AS spawn_id, npc_spawns.npc_id, npc_spawns.cell_number, '
                . 'npcs.npc_name_ru, npcs.npc_name_en, npcs.description, npcs.ai_behavior, npcs.faction_id')
            ->join('npcs', 'npcs.id = npc_spawns.npc_id')
            ->where('npc_spawns.cell_number', $cellNumber)
            ->where('npc_spawns.npc_id', $npcId)
            ->where('npc_spawns.status', 'alive')
            ->where('npcs.ai_behavior', 'passive')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * ADR-089 Фаза 2 — приветствие с учётом attitude: пробуем greeting_<attitude>,
     * затем базовое greeting. neutral → базовое.
     */
    public function greetingFor(int $npcId, string $attitude): string
    {
        if ($attitude !== '' && $attitude !== 'neutral') {
            $variant = $this->dialogues->lineFor($npcId, 'greeting_' . $attitude);
            if ($variant !== null) {
                return $variant;
            }
            // Фолбэк для attitude без засеянной строки.
            $fallback = match ($attitude) {
                'hostile'  => 'NPC узнаёт тебя. Рука тянется к оружию — прошлые встречи не забыты.',
                'wary'     => 'NPC смотрит настороженно, держа дистанцию.',
                'friendly' => 'NPC сдержанно кивает — он тебя помнит, и, похоже, неплохо.',
                default    => '',
            };
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return $this->line($npcId, 'greeting');
    }

    /** ADR-089 Фаза 4: killswitch NPC-квестгиверов. Default false → dormant. */
    public function questgiverEnabled(): bool
    {
        return $this->boolSetting('npc.questgiver_enabled', false);
    }

    /**
     * ADR-089 Фаза 4: квест, который NPC предлагает данному персонажу, ЕСЛИ доступен:
     * questgiver ON + у NPC задан offers_quest_title_en + квест active + startable-root
     * (extended_enabled+тип+без prerequisite) + по уровню + ещё не начат. Иначе null.
     *
     * @return array<int|string,mixed>|null
     */
    public function offeredQuestFor(int $charId, int $npcId): ?array
    {
        if (! $this->questgiverEnabled() || $charId <= 0 || $npcId <= 0) {
            return null;
        }
        $npc = $this->npcs->find($npcId);
        if (! is_array($npc)) {
            return null;
        }
        $titleRaw = $npc['offers_quest_title_en'] ?? null;
        $title    = is_string($titleRaw) ? $titleRaw : '';
        if ($title === '') {
            return null;
        }

        $quest = (new QuestModel())->where('title_en', $title)->where('status', 'active')->first();
        if (! is_array($quest)) {
            return null;
        }
        // Startable-корень (внутри проверяется quests.extended_enabled + тип + отсутствие prerequisite).
        if (! (new QuestChainService())->isExtendedStartableRoot($quest)) {
            return null;
        }

        // Уровень персонажа.
        $character = $this->characters->find($charId);
        $charLevel = 0;
        if ($character instanceof \App\Entities\CharacterEntity) {
            $lvlRaw    = $character['level'] ?? null;
            $charLevel = is_numeric($lvlRaw) ? (int) $lvlRaw : 0;
        }
        $minRaw   = $quest['min_level'] ?? null;
        $minLevel = is_numeric($minRaw) ? (int) $minRaw : 0;
        if ($charLevel < $minLevel) {
            return null;
        }

        // Уже начат/в работе?
        $questIdRaw = $quest['id'] ?? null;
        $questId    = is_numeric($questIdRaw) ? (int) $questIdRaw : 0;
        if ((new QuestStepsModel())->where(['quest_id' => $questId, 'character_id' => $charId])->first() !== null) {
            return null;
        }

        return $quest;
    }

    /**
     * ADR-089 Фаза 4: принять квест у NPC → создать quest_step (как GenericQuestStartAction).
     *
     * @return array{ok:bool, title?:string, reward?:int, description?:string}
     */
    public function acceptOfferedQuest(int $charId, int $npcId): array
    {
        $quest = $this->offeredQuestFor($charId, $npcId);
        if ($quest === null) {
            return ['ok' => false];
        }
        $questIdRaw = $quest['id'] ?? null;
        $questId    = is_numeric($questIdRaw) ? (int) $questIdRaw : 0;
        $titleRu    = is_string($quest['title_ru'] ?? null) ? $quest['title_ru'] : '';
        $descr      = is_string($quest['description'] ?? null) ? $quest['description'] : '';
        $rewardRaw  = $quest['reward'] ?? null;
        $reward     = is_numeric($rewardRaw) ? (int) $rewardRaw : 0;

        (new QuestStepsModel())->insert([
            'quest_id'     => $questId,
            'character_id' => $charId,
            'step_order'   => 1,
            'description'  => $titleRu !== '' ? $titleRu : 'Квест начат',
            'is_completed' => false,
        ]);

        return ['ok' => true, 'title' => $titleRu, 'reward' => $reward, 'description' => $descr];
    }

    /** Реплика заданного типа для NPC, с дефолтным фолбэком (контент полноценен без БД). */
    public function line(int $npcId, string $type): string
    {
        $line = $this->dialogues->lineFor($npcId, $type);
        if ($line !== null) {
            return $line;
        }

        // Скупой постапок-фолбэк, если контент не засеян.
        return match ($type) {
            'greeting'    => 'Незнакомец молча смотрит на тебя, держа руку у оружия.',
            'talk'        => '«Говорить особо не о чем. Пустошь не располагает к беседам.»',
            'ask'         => '«Дорог тут нет. Есть направления. И каждое может стать последним.»',
            'trade'       => '«Менять нечего. Всё, что было, давно ушло.»',
            'rob_success' => 'Ты застал его врасплох и забрал, что смог. Он молча отступает в пустошь.',
            'rob_fail'    => '«Зря ты так.» — он хватается за оружие.',
            default       => '…',
        };
    }

    /**
     * Грабёж: RNG-шанс. Успех → золото (one-shot, спавн уходит). Провал → бой.
     *
     * @return array{outcome:string, gold?:int, line:string, fight?:array<string,mixed>}
     */
    public function rob(int $playerId, int $spawnId): array
    {
        $spawn = $this->spawns->find($spawnId);
        if (! is_array($spawn)) {
            return ['outcome' => 'gone', 'line' => 'NPC уже исчез.'];
        }
        $npcIdRaw = $spawn['npc_id'] ?? null;
        $npcId    = is_numeric($npcIdRaw) ? (int) $npcIdRaw : 0;

        $chance = $this->floatSetting('npc.rob_success_chance', 0.45);
        // Грабёж по своей природе RNG.
        $roll = mt_rand(0, 9999) / 10000.0;

        if ($roll < $chance) {
            $min  = $this->intSetting('npc.rob_gold_min', 50);
            $max  = $this->intSetting('npc.rob_gold_max', 200);
            $gold = $min >= $max ? $min : mt_rand($min, $max);
            $this->characters->increaseGold($playerId, $gold);
            $this->consumeSpawn($spawnId);

            return ['outcome' => 'success', 'gold' => $gold, 'line' => $this->line($npcId, 'rob_success')];
        }

        // Провал → NPC даёт отпор: запускаем бой.
        $fight = $this->fight($playerId, $spawnId);

        return ['outcome' => 'fail', 'line' => $this->line($npcId, 'rob_fail'), 'fight' => $fight];
    }

    /**
     * Бой по инициативе игрока (опции «Напасть» / «Убить»). Переиспользует PvEService
     * (он сам обновляет игрока, начисляет награды, инкрементит npc_kills и шлёт уведомление).
     * Здесь дополнительно убираем спавн при победе игрока.
     *
     * @return array{ok:bool, won?:bool, summary?:string}
     */
    public function fight(int $playerId, int $spawnId): array
    {
        // CharacterModel возвращает CharacterEntity (ArrayAccess); attack() принимает её напрямую.
        $player = $this->characters->find($playerId);
        if (! $player instanceof \App\Entities\CharacterEntity) {
            return ['ok' => false];
        }
        $spawn = $this->spawns->find($spawnId);
        if (! is_array($spawn)) {
            return ['ok' => false];
        }

        $pCellRaw = $player['cell_number'] ?? null;
        $sCellRaw = $spawn['cell_number'] ?? null;
        $pCell    = is_numeric($pCellRaw) ? (int) $pCellRaw : -1;
        $sCell    = is_numeric($sCellRaw) ? (int) $sCellRaw : -2;
        if ($pCell !== $sCell) {
            return ['ok' => false];
        }

        // validateAndLoadNpc сам перезагружает спавн+npcs по ['id'] → достаточно spawn id.
        $result = $this->buildPveService()->attack($player, ['id' => $spawnId], 'Grasslands');

        $playerNameRaw = $player['name'] ?? null;
        $playerName    = is_string($playerNameRaw) ? $playerNameRaw : '';
        $winner        = $result['winner'] ?? null;
        $won           = is_object($winner) && isset($winner->name) && $winner->name === $playerName;

        if ($won) {
            $this->consumeSpawn($spawnId);
        }

        $summaryRaw = $result['message'] ?? null;

        return ['ok' => true, 'won' => $won, 'summary' => is_string($summaryRaw) ? $summaryRaw : ''];
    }

    /** Убираем спавн (победа/успешный грабёж) — как AutoPveHandler. */
    private function consumeSpawn(int $spawnId): void
    {
        $this->spawns->update($spawnId, ['status' => 'dead']);
        $this->spawns->delete($spawnId);
        $this->spawns->db->query('DELETE FROM npc_spawns WHERE id = ?', [$spawnId]);
    }

    private function buildPveService(): PvEService
    {
        $logger = new NullLogger();

        return new PvEService(
            new BattleService(new DamageService($logger), new EffectService($logger), new BattleLogger($logger), $logger),
            new RewardService(),
            new EquipmentService($logger),
            $this->characters,
            $this->spawns,
            $this->npcs
        );
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $v = $this->settings->get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }
}
