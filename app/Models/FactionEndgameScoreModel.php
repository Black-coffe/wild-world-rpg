<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * v0.51.110 — Endgame scenarios scoring model.
 *
 * Per-faction progress toward 1:1 mapped scenario:
 *   - Milistary (id=1) → Dominance
 *   - Partisans (id=2) → Anarchy
 *   - Engineers (id=3) → ScientificBreakthrough
 *   - Farmers (id=4) → Evacuation
 *
 * Threshold default 75000 (medium pace, ~3-6 months gameplay on 358 chars).
 * Когда threshold hit — `threshold_hit=1` + broadcast triggered у
 * EndgameProgressionService daily check.
 */
class FactionEndgameScoreModel extends Model
{
    protected $table         = 'faction_endgame_scores';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps = true;

    protected $allowedFields = [
        'faction_id',
        'scenario_name',
        'score',
        'threshold',
        'threshold_hit',
        'threshold_hit_at',
        'last_event_at',
    ];

    /**
     * Increment faction score by $delta. Returns new score after increment.
     * Updates last_event_at to NOW.
     */
    public function incrementScore(int $factionId, int $delta): int
    {
        $row = $this->where('faction_id', $factionId)->first();
        if (!$row) {
            return 0;
        }

        $newScore = (int) $row['score'] + $delta;
        $this->update($row['id'], [
            'score'         => $newScore,
            'last_event_at' => date('Y-m-d H:i:s'),
        ]);

        return $newScore;
    }

    /**
     * Find factions which hit their threshold AND mark threshold_hit=1.
     * Returns rows of newly-triggered scenarios.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkThresholds(): array
    {
        $triggered = $this
            ->where('threshold_hit', 0)
            ->where('score >= threshold', null, false)
            ->findAll();

        foreach ($triggered as $row) {
            $this->update($row['id'], [
                'threshold_hit'    => 1,
                'threshold_hit_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $triggered;
    }

    /**
     * Find first faction що уже hit threshold (для chronological "winner").
     *
     * @return array<string, mixed>|null
     */
    public function getActiveScenario(): ?array
    {
        return $this
            ->where('threshold_hit', 1)
            ->orderBy('threshold_hit_at', 'ASC')
            ->first();
    }
}
