<?php

declare(strict_types=1);

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\TelegramUserModel;
use App\Services\Bases\BaseLifecycleService;
use Config\Database;

/**
 * ADR-095 Фаза 2 (DORMANT) — суточный снос просроченных баз (base-TTL).
 *
 * База живёт `BaseLifecycleService::ttlDaysFor(level)` дней с последнего визита
 * (`claimed_cells.last_visited_at`). Истекла → сносим её со всеми постройками и уведомляем.
 *
 * 🔴 KILLSWITCH `buildings.lifecycle.ttl_enabled` (по умолчанию OFF). Пока выключен — handler
 * выходит мгновенно (dormant, byte-identical). Активация — решение владельца после валидации.
 *
 * Регистрация: Tasks.php `->daily('04:00')` (после налога 03:00). Налог-каскад — отдельно,
 * в TaxCollectionHandler (золото-триггер).
 */
#[HandlerKey(
    key: 'base_lifecycle',
    displayName: 'Срок жизни баз (раз в сутки)',
    description: 'DORMANT (killswitch buildings.lifecycle.ttl_enabled): сносит базы, не посещённые дольше ttlDaysFor(level), со всеми постройками.',
)]
class BaseLifecycleHandler extends BaseTaskHandler
{
    protected function isRoutineNotification(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $task recurring — без данных.
     */
    public function handle(array $task = []): void
    {
        $lifecycle = new BaseLifecycleService();
        if (! $lifecycle->ttlEnabled()) {
            return; // dormant — ничего не делаем
        }

        $db   = Database::connect();
        $rows = $db->table('claimed_cells cc')
            ->select('cc.id AS cell_row_id, cc.map_cell_id, cc.character_id, cc.last_visited_at, cc.camp_name, c.level, c.telegram_user_id')
            ->join('characters c', 'c.id = cc.character_id')
            ->where('cc.status', 'active')
            ->get();
        if ($rows === false) {
            return;
        }

        $claimedCellModel = new ClaimedCellModel();
        $buildingModel    = new CharacterBuildingModel();
        $tgUserModel      = new TelegramUserModel();
        $now              = time();

        foreach ($rows->getResultArray() as $row) {
            $level   = is_numeric($row['level'] ?? null) ? (int) $row['level'] : 1;
            $visited = $row['last_visited_at'] ?? null;
            if (! $lifecycle->isExpired($visited, $level, $now)) {
                continue;
            }

            $cellRowId = is_numeric($row['cell_row_id'] ?? null) ? (int) $row['cell_row_id'] : 0;
            $mapCellId = is_numeric($row['map_cell_id'] ?? null) ? (int) $row['map_cell_id'] : 0;
            $charId    = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            if ($cellRowId === 0) {
                continue;
            }

            // Сносим постройки этой базы + саму базу.
            $buildingModel->where('character_id', $charId)->where('map_cell_id', $mapCellId)->delete();
            $claimedCellModel->delete($cellRowId);

            $this->notifyExpired($tgUserModel, $row);
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function notifyExpired(TelegramUserModel $tgUserModel, array $row): void
    {
        $tgUserId = is_numeric($row['telegram_user_id'] ?? null) ? (int) $row['telegram_user_id'] : 0;
        if ($tgUserId === 0) {
            return;
        }
        $tgUser = $tgUserModel->find($tgUserId);
        $tgId = is_array($tgUser) ? ($tgUser['telegram_id'] ?? null) : null;
        if (! is_numeric($tgId)) {
            return;
        }
        $name = is_string($row['camp_name'] ?? null) && $row['camp_name'] !== '' ? $row['camp_name'] : 'База';
        $text = "🏚 *{$name} заброшена и разрушена.*\n\n"
            . "Ты давно не появлялся на этой базе — она пришла в негодность и исчезла вместе со всеми постройками.\n\n"
            . "_Заглядывай на свои базы почаще, чтобы они держались._";
        $this->safeSendMessage((int) $tgId, $text, ['parse_mode' => 'Markdown']);
    }
}
