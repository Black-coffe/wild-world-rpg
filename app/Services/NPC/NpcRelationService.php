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

    /** ADR-089 Фаза 5 — именованные ступени репутации (label для UI). */
    private const TIER_LABELS = [
        'enemy'        => 'Враг',
        'wary'         => 'Чужак',
        'neutral'      => 'Незнакомец',
        'acquaintance' => 'Знакомый',
        'ally'         => 'Союзник',
    ];

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
        $delta = $this->intSetting($key, 0);

        // ADR-089 Фаза 5 — предательство: враждебное действие против дружелюбного NPC бьёт сильнее.
        if ($delta < 0 && in_array($action, ['rob', 'kill', 'attack'], true)) {
            $scoreBefore = $this->effectiveScore($characterId, $npcId);
            if ($scoreBefore >= $this->intSetting('npc.relation_friendly_at', 30)) {
                $delta = (int) round($delta * $this->floatSetting('npc.relation_betrayal_mult', 1.0));
            }
        }

        $this->relations->adjust($characterId, $npcId, $delta);
    }

    /**
     * ADR-089 Фаза 5 — именованная ступень репутации NPC к персонажу.
     * При выключенной реактивности — neutral («Незнакомец»).
     *
     * @return array{tier:string, label:string}
     */
    public function standing(int $characterId, int $npcId): array
    {
        if (! $this->enabled()) {
            return ['tier' => 'neutral', 'label' => self::TIER_LABELS['neutral']];
        }
        $score      = $this->effectiveScore($characterId, $npcId);
        $hostileAt  = $this->intSetting('npc.relation_hostile_at', -50);
        $friendlyAt = $this->intSetting('npc.relation_friendly_at', 30);
        $allyAt     = $this->intSetting('npc.relation_ally_at', 80);

        $tier = match (true) {
            $score <= $hostileAt  => 'enemy',
            $score < 0            => 'wary',
            $score < $friendlyAt  => 'neutral',
            $score < $allyAt      => 'acquaintance',
            default               => 'ally',
        };

        return ['tier' => $tier, 'label' => self::TIER_LABELS[$tier]];
    }

    /** ADR-089 Phase 6 — порядок ступеней (для гейтинга опций диалога по standing). */
    private const TIER_ORDER = ['enemy' => 0, 'wary' => 1, 'neutral' => 2, 'acquaintance' => 3, 'ally' => 4];

    /**
     * ADR-089 Phase 6 — удовлетворяет ли текущая ступень минимальному гейту опции.
     * Пустой гейт = опция видна всегда. Неизвестный гейт = считаем выполненным (fail-open
     * на контент-ошибке, чтобы реплика не пропадала молча).
     */
    public function meetsStanding(int $characterId, int $npcId, string $minTier): bool
    {
        if ($minTier === '' || ! isset(self::TIER_ORDER[$minTier])) {
            return true;
        }
        $current = $this->standing($characterId, $npcId)['tier'];
        $curRank = self::TIER_ORDER[$current] ?? 2;

        return $curRank >= self::TIER_ORDER[$minTier];
    }

    /**
     * ADR-089 Фаза 5+ — применить произвольный сдвиг отношения (эффект ветки диалога).
     * No-op при выключенной реактивности.
     */
    public function adjustBy(int $characterId, int $npcId, int $delta): void
    {
        if (! $this->enabled() || $delta === 0 || $characterId <= 0 || $npcId <= 0) {
            return;
        }
        $this->relations->adjust($characterId, $npcId, $delta);
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

    private function floatSetting(string $key, float $default): float
    {
        $v = $this->settings->get($key, $default);

        return is_numeric($v) ? (float) $v : $default;
    }
}
