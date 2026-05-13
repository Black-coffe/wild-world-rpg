<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FactionEndgameScoreModel;
use App\Services\Endgame\SeasonResetService;
use CodeIgniter\HTTP\RedirectResponse;
use Config\EndgameScoring;

/**
 * v0.51.114 — Endgame admin dashboard (Step 5).
 *
 * Read-only viewer + manual reset endpoints для endgame scenario tracking.
 * Routes: /admin/endgame (GET), /admin/endgame/reset/<id> (POST).
 *
 * Phase A2 рефакторинг (2026-05-13): extends `BaseAdminController`.
 * **Bug-fix:** оригинальный код звал `$this->auditModel->logAction(...)` (метод
 * не существует) и `service('auth')->getCurrentUser()` (метод на кастомной
 * `Authentication`, а `service('auth')` возвращает Shield Auth). Оба пути
 * `reset/resetSeason` фатал-эррорили бы при клике — теперь используют
 * `$this->audit(...)` из `BaseAdminController`.
 */
class EndgameController extends BaseAdminController
{
    private FactionEndgameScoreModel $scoreModel;
    private EndgameScoring $cfg;

    public function __construct()
    {
        $this->scoreModel = new FactionEndgameScoreModel();
        $cfg              = config(EndgameScoring::class);
        $this->cfg        = $cfg;
    }

    public function index(): string
    {
        $rows = $this->scoreModel->orderBy('faction_id', 'ASC')->findAll();

        $factionNames = [
            1 => 'Милитари',
            2 => 'Партизаны',
            3 => 'Инженеры',
            4 => 'Фермеры',
        ];

        return view('admin/endgame_dashboard', [
            'title'        => 'Endgame Scenarios — Faction Scores',
            'rows'         => $rows,
            'factionNames' => $factionNames,
            'scenarioRu'   => $this->cfg->scenarioRu,
        ]);
    }

    public function reset(int|string $id): RedirectResponse
    {
        $rowRaw = $this->scoreModel->find((int) $id);
        if ($rowRaw === null) {
            return redirect()->to('/admin/endgame')->with('error', 'Запись не найдена');
        }
        $row = (array) $rowRaw;

        $beforeScore = (int) ($row['score'] ?? 0);
        $this->scoreModel->update((int) $id, [
            'score'            => 0,
            'threshold_hit'    => 0,
            'threshold_hit_at' => null,
            'last_event_at'    => null,
        ]);

        $this->audit(
            'ENDGAME_RESET',
            'faction_endgame_scores',
            (int) $id,
            [
                'faction_id'   => (int) ($row['faction_id'] ?? 0),
                'before_score' => $beforeScore,
            ],
        );

        $factionId    = (int) ($row['faction_id'] ?? 0);
        $scenarioName = (string) ($row['scenario_name'] ?? '');

        return redirect()->to('/admin/endgame')->with(
            'success',
            "Score сброшен для faction {$factionId} ({$scenarioName}) — было {$beforeScore} pts",
        );
    }

    /**
     * v0.51.120 — Season reset (Endgame B).
     *
     * POST /admin/endgame/reset-season — full "new season" trigger.
     * Bulk reset усіх 4 faction scores + усіх character endgame_state →
     * 'active'. Audit logged.
     */
    public function resetSeason(): RedirectResponse
    {
        $resetSvc = new SeasonResetService();
        $stats    = $resetSvc->reset();

        $this->audit('ENDGAME_SEASON_RESET', 'faction_endgame_scores', 0, $stats);

        return redirect()->to('/admin/endgame')->with(
            'success',
            "🔄 Season reset: {$stats['scores_reset']} scores wiped, {$stats['chars_unfrozen']} characters back to 'active' state.",
        );
    }
}
