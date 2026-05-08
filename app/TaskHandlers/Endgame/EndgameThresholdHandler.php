<?php

declare(strict_types=1);

namespace App\TaskHandlers\Endgame;

use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\FactionEndgameScoreModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;
use Config\EndgameScoring;

/**
 * v0.51.113 — Endgame threshold check + scenario activation (Step 4).
 *
 * Daily cron handler. Перевіряє чи якась faction досягла 75k threshold;
 * якщо так — triggers full endgame mechanic:
 *   1. faction_endgame_scores.threshold_hit=1 (через checkThresholds())
 *   2. broadcast notification до всіх active chars
 *   3. updates characters.endgame_state:
 *      - winning faction → 'won'
 *      - other factions → 'lost'
 *      - neutrals (id=5/no faction) → 'active' (not affected)
 *
 * Idempotent: якщо вже triggered — повторний run no-op (checkThresholds
 * фільтрує по `threshold_hit=0`).
 *
 * Wire-in: Tasks.php daily entry о 04:00 EEST (after tax collection 03:00 +
 * battles cleanup 03:35).
 */
class EndgameThresholdHandler extends BaseTaskHandler
{
    private FactionEndgameScoreModel $scoreModel;
    private CharacterModel $characterModel;
    private CharacterFactionModel $charFactionModel;
    private TelegramUserModel $tgUserModel;
    private EndgameScoring $cfg;

    public function __construct()
    {
        $this->scoreModel       = new FactionEndgameScoreModel();
        $this->characterModel   = new CharacterModel();
        $this->charFactionModel = new CharacterFactionModel();
        $this->tgUserModel      = new TelegramUserModel();
        $this->cfg              = config('EndgameScoring');
    }

    public function handle(array $task = []): void
    {
        $triggered = $this->scoreModel->checkThresholds();
        if (empty($triggered)) {
            return;
        }

        // Take first triggered (chronological winner). У будь-якому випадку
        // після першого triggered гра у "ending" state — наступні факції
        // які ще накопичуються не змінюють фінал.
        $winner = $triggered[0];
        $this->activateScenario($winner);
    }

    /**
     * @param array<string, mixed> $winnerScore row з faction_endgame_scores
     */
    private function activateScenario(array $winnerScore): void
    {
        $winnerFactionId = (int) $winnerScore['faction_id'];
        $scenarioName    = (string) $winnerScore['scenario_name'];
        $scenarioRu      = $this->cfg->scenarioRu[$scenarioName] ?? $scenarioName;

        log_message('info', "[EndgameThresholdHandler] FINAL TRIGGERED: faction={$winnerFactionId} scenario={$scenarioName}");

        // Update all characters' endgame_state.
        $this->updateCharacterStates($winnerFactionId);

        // Broadcast to all active chars.
        $this->broadcastFinal($scenarioRu, $winnerFactionId);
    }

    private function updateCharacterStates(int $winnerFactionId): void
    {
        $now = date('Y-m-d H:i:s');

        // Winners.
        $winnerCharIds = array_column(
            $this->charFactionModel->where('faction_id', $winnerFactionId)->findAll(),
            'character_id'
        );
        if (!empty($winnerCharIds)) {
            $this->characterModel->whereIn('id', $winnerCharIds)->set([
                'endgame_state'   => 'won',
                'endgame_lock_at' => $now,
            ])->update();
        }

        // Losers (other 3 factions, NOT id=5 Нейтрал).
        $loserCharIds = array_column(
            $this->charFactionModel
                ->whereNotIn('faction_id', [$winnerFactionId, 5])
                ->where('faction_id <=', 4)
                ->findAll(),
            'character_id'
        );
        if (!empty($loserCharIds)) {
            $this->characterModel->whereIn('id', $loserCharIds)->set([
                'endgame_state'   => 'lost',
                'endgame_lock_at' => $now,
            ])->update();
        }
    }

    private function broadcastFinal(string $scenarioRu, int $winnerFactionId): void
    {
        $factionName = $this->resolveFactionName($winnerFactionId);

        $msg  = "🌍 *НАСТУПИЛ ФИНАЛ ИГРЫ*\n\n";
        $msg .= "Сценарий: *{$scenarioRu}*\n";
        $msg .= "Победила фракция: *{$factionName}*\n\n";
        $msg .= "_Этот сервер достиг конечной точки своей истории._\n\n";
        $msg .= "Если ты был у победившей фракции — поздравляем! Твой персонаж достиг финала.\n";
        $msg .= "Если был у другой — продолжай играть на новых серверах. Этот мир завершён.";

        // Find all active chars з telegram_id (через JOIN).
        $rows = $this->characterModel
            ->select('characters.id, telegram_users.telegram_id')
            ->join('telegram_users', 'telegram_users.id = characters.telegram_user_id')
            ->where('characters.endgame_state IN', ['won', 'lost'], false)
            ->findAll();

        foreach ($rows as $row) {
            $tgId = $row['telegram_id'] ?? null;
            if ($tgId === null) {
                continue;
            }
            $this->safeSendMessage((int) $tgId, $msg, ['parse_mode' => 'Markdown']);
        }

        log_message('info', "[EndgameThresholdHandler] Broadcast sent to " . count($rows) . " chars");
    }

    private function resolveFactionName(int $factionId): string
    {
        $names = [
            1 => 'Милитари',
            2 => 'Партизаны',
            3 => 'Инженеры',
            4 => 'Фермеры',
        ];
        return $names[$factionId] ?? 'Unknown';
    }
}
