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
 * ADR-095 Фаза 2 (DORMANT) + ADR-125 / E26 «мягкий режим» — суточный жизненный цикл баз (base-TTL).
 *
 * База живёт `BaseLifecycleService::ttlDaysFor(level)` дней с последнего визита
 * (`claimed_cells.last_visited_at`). Два независимых killswitch'а для поэтапной активации:
 *  - 🔴 `buildings.lifecycle.warn_enabled` (OFF) — фаза A: эскалирующие ПРЕДУПРЕЖДЕНИЯ за warn_days
 *    дней до сноса (CTA «зайди — таймер сбросится»), сам снос НЕ происходит.
 *  - 🔴 `buildings.lifecycle.ttl_enabled` (OFF) — фаза B: просроченная база сносится со всеми
 *    постройками + уведомление.
 * Оба OFF — handler выходит мгновенно (dormant, byte-identical). Активация — решение владельца
 * (E26: warn_enabled первым, ttl_enabled позже).
 *
 * Регистрация: Tasks.php `->daily('04:00')` (после налога 03:00). Налог-каскад — отдельно,
 * в TaxCollectionHandler (золото-триггер).
 */
#[HandlerKey(
    key: 'base_lifecycle',
    displayName: 'Срок жизни баз (раз в сутки)',
    description: 'DORMANT (killswitch warn_enabled / ttl_enabled): предупреждает о простаивающих базах за warn_days дней (warn-фаза) и сносит просроченные со всеми постройками (ttl-фаза).',
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
        $ttlOn     = $lifecycle->ttlEnabled();
        $warnOn    = $lifecycle->warnEnabled();
        if (! $ttlOn && ! $warnOn) {
            return; // dormant — ничего не делаем
        }

        $db   = Database::connect();
        $rows = $db->table('claimed_cells cc')
            ->select('cc.id AS cell_row_id, cc.map_cell_id, cc.character_id, cc.last_visited_at, cc.last_warned_at, cc.camp_name, c.level, c.telegram_user_id')
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
            $level     = is_numeric($row['level'] ?? null) ? (int) $row['level'] : 1;
            $visited   = $row['last_visited_at'] ?? null;
            $cellRowId = is_numeric($row['cell_row_id'] ?? null) ? (int) $row['cell_row_id'] : 0;
            if ($cellRowId === 0) {
                continue;
            }

            // Фаза B: просрочена и ttl_enabled ON → снос базы со всеми постройками.
            if ($ttlOn && $lifecycle->isExpired($visited, $level, $now)) {
                $mapCellId = is_numeric($row['map_cell_id'] ?? null) ? (int) $row['map_cell_id'] : 0;
                $charId    = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
                $buildingModel->where('character_id', $charId)->where('map_cell_id', $mapCellId)->delete();
                $claimedCellModel->delete($cellRowId);
                $this->notifyExpired($tgUserModel, $row);
                continue;
            }

            // Фаза A: warn_enabled ON и база в окне предупреждения → эскалирующее предупреждение.
            if ($warnOn && $lifecycle->shouldWarn($visited, $level, $row['last_warned_at'] ?? null, $now)) {
                $daysLeft = $lifecycle->daysRemaining($visited, $level, $now) ?? 0;
                $this->notifyWarn($tgUserModel, $row, $daysLeft);
                $claimedCellModel->update($cellRowId, ['last_warned_at' => date('Y-m-d H:i:s', $now)]);
            }
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

    /**
     * ADR-125 / E26 (warn-фаза) — эскалирующее предупреждение о простаивающей базе.
     * НЕ сносит — только напоминает зайти, чтобы сбросить таймер.
     *
     * @param array<string,mixed> $row
     */
    private function notifyWarn(TelegramUserModel $tgUserModel, array $row, int $daysLeft): void
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

        if ($daysLeft <= 0) {
            $text = "⚠️ *{$name} простаивает и скоро будет разрушена.*\n\n"
                . "Срок присмотра за базой истёк. Если не заглянуть — она придёт в негодность и исчезнет со всеми постройками.\n\n"
                . "_Загляни на базу (🏠 База) — таймер сбросится._";
        } else {
            $dayWord = $this->pluralDays($daysLeft);
            $text = "⏳ *{$name} простаивает.*\n\n"
                . "До разрушения базы осталось *{$daysLeft} {$dayWord}*. Без присмотра она исчезнет со всеми постройками.\n\n"
                . "_Загляни на базу (🏠 База) — таймер сбросится._";
        }
        $this->safeSendMessage((int) $tgId, $text, ['parse_mode' => 'Markdown']);
    }

    /** Склонение слова «день» под число (1 день / 2 дня / 5 дней). */
    private function pluralDays(int $n): string
    {
        $n   = abs($n);
        $mod100 = $n % 100;
        $mod10  = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'дней';
        }
        if ($mod10 === 1) {
            return 'день';
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return 'дня';
        }
        return 'дней';
    }
}
