<?php

declare(strict_types=1);

namespace App\Services\Endgame;

use App\Models\CharacterModel;
use App\Models\FactionEndgameScoreModel;

/**
 * v0.51.120 — Season reset service (Endgame B).
 *
 * Wraps "new season" reset logic: bulk wipe всіх faction scores + bulk
 * reset characters endgame_state. Idempotent (повторний run — no-op
 * якщо все вже cleared).
 *
 * Triggered manually через admin POST /admin/endgame/reset-season.
 * Audit logged через caller (EndgameController).
 */
final class SeasonResetService
{
    public function __construct(
        private ?FactionEndgameScoreModel $scoreModel = null,
        private ?CharacterModel $characterModel = null
    ) {
        $this->scoreModel     = $scoreModel ?? new FactionEndgameScoreModel();
        $this->characterModel = $characterModel ?? new CharacterModel();
    }

    /**
     * Reset season:
     *   1. faction_endgame_scores: score=0, threshold_hit=0, threshold_hit_at=null,
     *      last_event_at=null для усіх 4 рядків.
     *   2. characters: endgame_state='active', endgame_lock_at=null для усіх
     *      chars де endgame_state IN ('won','lost','frozen','evacuated').
     *
     * @return array{
     *   scores_reset: int,
     *   chars_unfrozen: int
     * }
     */
    public function reset(): array
    {
        // 1. Reset all 4 faction scores (idempotent — sets back to default).
        $this->scoreModel
            ->set([
                'score'            => 0,
                'threshold_hit'    => 0,
                'threshold_hit_at' => null,
                'last_event_at'    => null,
            ])
            ->where('id >', 0) // affect усі rows
            ->update();
        $scoresReset = $this->scoreModel->countAllResults(false);

        // 2. Reset characters back до 'active'.
        $this->characterModel
            ->set([
                'endgame_state'   => 'active',
                'endgame_lock_at' => null,
            ])
            ->whereIn('endgame_state', ['won', 'lost', 'frozen', 'evacuated'])
            ->update();

        // Count affected (re-query bo CI4 update() не повертає affected count
        // консистентно через `set + whereIn` chain).
        $charsReset = $this->characterModel
            ->where('endgame_lock_at IS NULL')
            ->where('endgame_state', 'active')
            ->countAllResults();

        return [
            'scores_reset'    => $scoresReset,
            'chars_unfrozen'  => $charsReset,
        ];
    }
}
