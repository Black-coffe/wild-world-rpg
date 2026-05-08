<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AdminAuditLogModel;
use App\Models\FactionEndgameScoreModel;
use Config\EndgameScoring;

/**
 * v0.51.114 — Endgame admin dashboard (Step 5).
 *
 * Read-only viewer + manual reset endpoints для endgame scenario tracking.
 * Routes: /admin/endgame (GET), /admin/endgame/reset/<id> (POST).
 */
class EndgameController extends BaseController
{
    private FactionEndgameScoreModel $scoreModel;
    private AdminAuditLogModel $auditModel;
    private EndgameScoring $cfg;

    public function __construct()
    {
        $this->scoreModel = new FactionEndgameScoreModel();
        $this->auditModel = new AdminAuditLogModel();
        $this->cfg        = config('EndgameScoring');
    }

    public function index()
    {
        $rows = $this->scoreModel->orderBy('faction_id', 'ASC')->findAll();

        $factionNames = [
            1 => 'Милитари',
            2 => 'Партизаны',
            3 => 'Инженеры',
            4 => 'Фермеры',
        ];

        $data = [
            'title'         => 'Endgame Scenarios — Faction Scores',
            'rows'          => $rows,
            'factionNames'  => $factionNames,
            'scenarioRu'    => $this->cfg->scenarioRu,
        ];

        return view('admin/endgame_dashboard', $data);
    }

    public function reset($id)
    {
        $row = $this->scoreModel->find((int) $id);
        if (!$row) {
            return redirect()->to('/admin/endgame')->with('error', 'Запись не найдена');
        }

        $beforeScore = (int) $row['score'];
        $this->scoreModel->update((int) $id, [
            'score'            => 0,
            'threshold_hit'    => 0,
            'threshold_hit_at' => null,
            'last_event_at'    => null,
        ]);

        $this->auditModel->logAction(
            (int) (service('auth')->getCurrentUser()['id'] ?? 0),
            'ENDGAME_RESET',
            'faction_endgame_scores',
            (int) $id,
            ['faction_id' => (int) $row['faction_id'], 'before_score' => $beforeScore]
        );

        return redirect()->to('/admin/endgame')->with(
            'success',
            "Score сброшен для faction {$row['faction_id']} ({$row['scenario_name']}) — было {$beforeScore} pts"
        );
    }
}
