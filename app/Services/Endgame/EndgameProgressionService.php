<?php

declare(strict_types=1);

namespace App\Services\Endgame;

use App\Models\CharacterFactionModel;
use App\Models\FactionEndgameScoreModel;
use Config\EndgameScoring;

/**
 * v0.51.111 — Endgame scenarios progression service (Step 2).
 *
 * Wires gameplay events → faction score increments. Caller invokes
 * specific record* method, service routes to correct faction (через
 * EndgameScoring config maps) і increments DB score.
 *
 * Threshold check delegated до FactionEndgameScoreModel::checkThresholds()
 * (called from daily cron у EndgameThresholdHandler).
 *
 * Service no-op якщо character already у frozen/won/lost state — гра вже
 * закінчена для цього character.
 */
final class EndgameProgressionService
{
    public function __construct(
        private ?FactionEndgameScoreModel $scoreModel = null,
        private ?CharacterFactionModel $charFactionModel = null,
        private ?EndgameScoring $cfg = null
    ) {
        $this->scoreModel       = $scoreModel ?? new FactionEndgameScoreModel();
        $this->charFactionModel = $charFactionModel ?? new CharacterFactionModel();
        $this->cfg              = $cfg ?? config('EndgameScoring');
    }

    /**
     * PvP kill — points to winner's faction.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $winnerCharacter
     */
    public function recordPvpKill(array|\App\Entities\CharacterEntity $winnerCharacter): void
    {
        $factionId = $this->resolveCharacterFaction((int) $winnerCharacter['id']);
        if ($factionId === null) {
            return;
        }
        $this->scoreModel->incrementScore($factionId, $this->cfg->pvpKillPoints);
    }

    /**
     * Quest completed — points to character's faction.
     */
    public function recordQuestCompletion(int $characterId): void
    {
        $factionId = $this->resolveCharacterFaction($characterId);
        if ($factionId === null) {
            return;
        }
        $this->scoreModel->incrementScore($factionId, $this->cfg->questCompletionPoints);
    }

    /**
     * Building upgrade — points до faction tied to building.
     */
    public function recordBuildingUpgrade(string $buildingNameEn): void
    {
        $factionId = $this->cfg->buildingFactionMap[$buildingNameEn] ?? null;
        if ($factionId === null) {
            return;
        }
        $this->scoreModel->incrementScore($factionId, $this->cfg->buildingUpgradePoints);
    }

    /**
     * Strategic object discovered — points до scenario faction.
     */
    public function recordStrategicObjectDiscovery(string $objectNameEn): void
    {
        $factionId = $this->cfg->strategicObjectFactionMap[$objectNameEn] ?? null;
        if ($factionId === null) {
            return;
        }
        $this->scoreModel->incrementScore($factionId, $this->cfg->strategicObjectDiscoveryPoints);
    }

    /**
     * Crafted item produced — points до character's faction.
     */
    public function recordCraftedItem(int $characterId): void
    {
        $factionId = $this->resolveCharacterFaction($characterId);
        if ($factionId === null) {
            return;
        }
        $this->scoreModel->incrementScore($factionId, $this->cfg->craftedItemPoints);
    }

    /**
     * Lookup character's current faction. Returns null якщо character НЕ
     * у факції (e.g. Нейтрал id=5) або не знайдено.
     */
    private function resolveCharacterFaction(int $characterId): ?int
    {
        $row = $this->charFactionModel
            ->where('character_id', $characterId)
            ->first();
        if (!$row) {
            return null;
        }
        $factionId = (int) ($row['faction_id'] ?? 0);
        // Skip Нейтралы (id=5) і undefined.
        if ($factionId < 1 || $factionId > 4) {
            return null;
        }
        return $factionId;
    }
}
