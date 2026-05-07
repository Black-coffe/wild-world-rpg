<?php

namespace App\Services\Player\Relocation;

use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\TaskModel;
use CodeIgniter\I18n\Time;

/**
 * v0.51.47 (BaseShiftingCommand decomp Step 1) — переніс 3 валідаційні
 * helpers з `BaseShiftingCommand` (530 LOC) у dedicated сервіс.
 *
 * Public API:
 *   - checkPreconditions(character): cooldown + active task check
 *   - isCellAvailableForRelocation(charId, mapCellId, &reason): claimed_cells + active relocations
 *   - isCellExploredBy(charId, mapCellId): explored_cells lookup
 *   - getFullRelocationTaskId(): lazy lookup/create FullRelocation task row
 *
 * Both entry points (`execute()` + `handleCallback()`) у BaseShiftingCommand
 * раніше дублювали виклики цих 3 helpers. Тепер shared service.
 *
 * Behavior preservation: 1:1 з оригіналом. Жодних формул не змінено.
 */
class RelocationValidator
{
    private TaskModel $taskModel;
    private CharacterTaskModel $characterTaskModel;
    private ClaimedCellModel $claimedCellModel;
    private ExploredCellsModel $exploredCellsModel;

    public function __construct(
        ?TaskModel $taskModel = null,
        ?CharacterTaskModel $characterTaskModel = null,
        ?ClaimedCellModel $claimedCellModel = null,
        ?ExploredCellsModel $exploredCellsModel = null
    ) {
        $this->taskModel          = $taskModel          ?? new TaskModel();
        $this->characterTaskModel = $characterTaskModel ?? new CharacterTaskModel();
        $this->claimedCellModel   = $claimedCellModel   ?? new ClaimedCellModel();
        $this->exploredCellsModel = $exploredCellsModel ?? new ExploredCellsModel();
    }

    /**
     * Lazy lookup/create FullRelocation task row у tasks table.
     * Використовується усіма іншими методами та зовнішніми callers.
     *
     * @return int task_id
     */
    public function getFullRelocationTaskId(): int
    {
        $frTask = $this->taskModel->where('name', 'FullRelocation')->first();
        if (!$frTask) {
            return (int) $this->taskModel->insert([
                'name'                       => 'FullRelocation',
                'name_rus'                   => 'Полноценный переезд',
                'description'                => '24h + 10d cooldown',
                'min_duration'               => 1440,
                'max_duration'               => 1440,
                'type'                       => 'exclusive',
                'difficulty_level'           => 7,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'              => 1,
            ]);
        }
        return (int) $frTask['id'];
    }

    /**
     * Перевіряємо кулдаун (10 днів між переїздами) + чи вже не йде активна
     * FullRelocation задача.
     *
     * @return array{ok: bool, error?: string, fullRelocTaskId?: int}
     */
    public function checkPreconditions(array|\App\Entities\CharacterEntity $character): array
    {
        $frTaskId = $this->getFullRelocationTaskId();

        // Активна задача?
        $active = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $frTaskId)
            ->where('status', 'in_work')
            ->first();
        if ($active) {
            return ['ok' => false, 'error' => "❌ У вас уже идёт полный переезд! Дождитесь окончания."];
        }

        // Кулдаун?
        $lastCompl = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $frTaskId)
            ->where('status', 'completed')
            ->orderBy('created_at', 'DESC')
            ->first();
        if ($lastCompl && !empty($lastCompl['end_time'])) {
            $endTime  = new Time($lastCompl['end_time']);
            $now      = Time::now();
            $diffDays = $endTime->difference($now)->getDays();
            if ($diffDays < 10) {
                $need = 10 - $diffDays;
                return ['ok' => false, 'error' => "❌ С прошлого переезда прошло {$diffDays} дн. Нужно 10 дн. ожидания, осталось ~{$need} дн."];
            }
        }

        return ['ok' => true, 'fullRelocTaskId' => $frTaskId];
    }

    /**
     * Перевірка, чи можна переїхати у ячейку (mapCellId): нема активних
     * claimed_cells на ній та нема in_work FullRelocation що цілитися сюди.
     *
     * @param string $reason out — причина "Занята" / "Резерв" / etc.
     */
    public function isCellAvailableForRelocation(int $charId, int $mapCellId, string &$reason): bool
    {
        $reason = '';

        // 1) claimed_cells
        $row = $this->claimedCellModel
            ->where('map_cell_id', $mapCellId)
            ->where('status', 'active')
            ->where('character_id !=', $charId)
            ->first();
        if ($row) {
            $reason = "Занята другим игроком";
            return false;
        }

        // 2) active FullRelocation з new_map_cell_id = $mapCellId?
        $frTask = $this->taskModel->where('name', 'FullRelocation')->first();
        if ($frTask) {
            $frTaskId = $frTask['id'];
            $busy = $this->characterTaskModel
                ->where('status', 'in_work')
                ->where('task_id', $frTaskId)
                ->like('task_settings', '"new_map_cell_id":' . $mapCellId, 'both')
                ->first();
            if ($busy && $busy['character_id'] != $charId) {
                $reason = "Туда уже переезжает другой игрок";
                return false;
            }
        }

        return true;
    }

    /**
     * Чи персонаж вже вивчав ячейку.
     */
    public function isCellExploredBy(int $charId, int $mapCellId): bool
    {
        $row = $this->exploredCellsModel
            ->where('character_id', $charId)
            ->where('map_cell_id', $mapCellId)
            ->first();
        return (bool) $row;
    }
}
