<?php

namespace App\Services\Player\Relocation;

use App\Models\CharacterTaskModel;
use CodeIgniter\I18n\Time;

/**
 * v0.51.49 (BaseShiftingCommand decomp Step 3) — extract DB write portion
 * `startRelocationTask` у dedicated сервіс.
 *
 * Original method у BaseShiftingCommand робив 2 речі (SRP violation):
 *  1. Insert character_task row.
 *  2. Send Telegram message.
 *
 * Тепер service робить тільки (1) — DB write. UI/Telegram send залишається
 * у calling code (handleCallback). Separation of concerns: persistence vs UI.
 *
 * Public API:
 *  - createTask(charId, telegramUserId, frTaskId, mapCellId, targetX, targetY): void
 *
 * Hardcoded duration 24h (1440 minutes) — matches original constant у migration
 * `tasks` table FullRelocation row.
 */
class RelocationTaskCreator
{
    private CharacterTaskModel $characterTaskModel;

    public function __construct(?CharacterTaskModel $characterTaskModel = null)
    {
        $this->characterTaskModel = $characterTaskModel ?? new CharacterTaskModel();
    }

    /**
     * Insert character_task row для FullRelocation. Status = 'in_work',
     * end_time = now + 24h. task_settings JSON з new_map_cell_id (читається
     * BaseFullRelocationCompletionHandler у Worker.php).
     */
    public function createTask(
        int $charId,
        int $telegramUserId,
        int $frTaskId,
        int $mapCellId,
        int $targetX,
        int $targetY,
        int $sourceMapCellId = 0
    ): void {
        $now = Time::now();
        $end = $now->addHours(24);

        $settings = [
            'new_map_cell_id'     => $mapCellId,
            // ADR-102: исходная база, которую переносим (мультибэйс) — completion-handler
            // двигает ТОЛЬКО её постройки. 0 = legacy fallback (перенос всех баз).
            'source_map_cell_id'  => $sourceMapCellId,
            'note'                => "Переезд в X={$targetX},Y={$targetY} (map_id={$mapCellId})",
        ];

        $this->characterTaskModel->insert([
            'character_id'     => $charId,
            'telegram_user_id' => $telegramUserId,
            'task_id'          => $frTaskId,
            'start_time'       => $now->toDateTimeString(),
            'end_time'         => $end->toDateTimeString(),
            'status'           => 'in_work',
            'task_settings'    => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
