<?php

declare(strict_types=1);

namespace App\Services\NPC;

use App\Models\CharacterFactionModel;
use App\Models\CharacterNpcRelationModel;
use App\Models\NpcModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-089 Фаза 2 — реактивность NPC: память отношения + attitude.
 *
 * Отношение хранится per (character, npc_id) в character_npc_relations и меняется от
 * действий игрока. attitude() = stored relation + faction-модификатор → hostile/wary/
 * neutral/friendly. Влияет на приветствие и доступные действия в экране встречи.
 * Killswitch `npc.reactivity_enabled` (OFF → всегда neutral, память не влияет).
 */
final class NpcRelationService
{
    public const HOSTILE  = 'hostile';
    public const WARY     = 'wary';
    public const NEUTRAL  = 'neutral';
    public const FRIENDLY = 'friendly';

    private GameSettingsService $settings;
    private CharacterNpcRelationModel $relations;
    private NpcModel $npcs;
    private CharacterFactionModel $factions;

    public function __construct(
        ?GameSettingsService $settings = null,
        ?CharacterNpcRelationModel $relations = null,
        ?NpcModel $npcs = null,
        ?CharacterFactionModel $factions = null
    ) {
        $this->settings  = $settings ?? new GameSettingsService();
        $this->relations = $relations ?? new CharacterNpcRelationModel();
        $this->npcs      = $npcs ?? new NpcModel();
        $this->factions  = $factions ?? new CharacterFactionModel();
    }

    /** Killswitch реактивности. Default false → память не влияет (всегда neutral). */
    public function enabled(): bool
    {
        $v = $this->settings->get('npc.reactivity_enabled', false);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Зарегистрировать действие игрока против/с NPC → сдвиг отношения.
     * $action ∈ rob/kill/attack/talk/trade. No-op при выключенной реактивности.
     */
    public function registerAction(int $characterId, int $npcId, string $action): void
    {
        if (! $this->enabled() || $characterId <= 0 || $npcId <= 0) {
            return;
        }
        $key = match ($action) {
            'rob'    => 'npc.relation_rob',
            'kill'   => 'npc.relation_kill',
            'attack' => 'npc.relation_attack',
            'talk', 'ask' => 'npc.relation_talk',
            'trade'  => 'npc.relation_trade',
            default  => null,
        };
        if ($key === null) {
            return;
        }
        $this->relations->adjust($characterId, $npcId, $this->intSetting($key, 0));
    }

    /**
     * Эффективное отношение = stored relation + faction-модификатор.
     */
    public function effectiveScore(int $characterId, int $npcId): int
    {
        $score = $this->relations->scoreFor($characterId, $npcId);

        return $score + $this->factionModifier($characterId, $npcId);
    }

    /**
     * Attitude NPC к персонажу. При выключенной реактивности — всегда neutral.
     */
    public function attitude(int $characterId, int $npcId): string
    {
        if (! $this->enabled()) {
            return self::NEUTRAL;
        }
        $score      = $this->effectiveScore($characterId, $npcId);
        $hostileAt  = $this->intSetting('npc.relation_hostile_at', -50);
        $friendlyAt = $this->intSetting('npc.relation_friendly_at', 30);

        if ($score <= $hostileAt) {
            return self::HOSTILE;
        }
        if ($score >= $friendlyAt) {
            return self::FRIENDLY;
        }
        if ($score < 0) {
            return self::WARY;
        }

        return self::NEUTRAL;
    }

    /** Модификатор от фракций: NPC.faction_id (1-4) vs фракция игрока. NULL/0 NPC → 0. */
    private function factionModifier(int $characterId, int $npcId): int
    {
        $npc = $this->npcs->find($npcId);
        if (! is_array($npc)) {
            return 0;
        }
        $npcFacRaw = $npc['faction_id'] ?? null;
        $npcFac    = is_numeric($npcFacRaw) ? (int) $npcFacRaw : 0;
        if ($npcFac < 1 || $npcFac > 4) {
            return 0; // нейтральный/безфракционный NPC
        }
        $playerFac = $this->factions->getFactionId($characterId);
        if ($playerFac < 1 || $playerFac > 4) {
            return 0; // игрок без фракции
        }

        return $playerFac === $npcFac
            ? $this->intSetting('npc.relation_faction_same', 25)
            : $this->intSetting('npc.relation_faction_other', -15);
    }

    private function intSetting(string $key, int $default): int
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }
}
