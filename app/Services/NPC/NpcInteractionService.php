<?php

declare(strict_types=1);

namespace App\Services\NPC;

use App\Models\CharacterModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\NpcDialogueModel;
use App\Services\GameSettings\GameSettingsService;
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
            new RewardService($logger),
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
