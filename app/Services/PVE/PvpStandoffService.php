<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\ActionLogModel;
use App\Models\PvpStandoffModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\GameSettings\GameSettingsService;
use Config\Database;
use Throwable;

/**
 * ADR-186 (pvp-detection-clarity-06) — сердце окна противостояния: открыть без
 * гонки, прочитать активное с ленивым истечением, закрыть ровно один раз,
 * удержать кулдаун защитника. Потребители (`AttackPlayerAction`, `RunAwayAction`,
 * `StandoffCheckAction`/`StandoffLeaveAction`/`StandoffHoldAction`,
 * `StandoffExpiryHandler`) едут отдельными story `-08`/`-09`/`-10` — этот сервис
 * никем ещё не вызывается.
 *
 * 🔴 Инвариант 1 (ADR-186): открытость окна вычисляется в момент ЧТЕНИЯ
 * (`status='open' AND expires_at > NOW()`), а не по факту прохода крона —
 * зависший крон не держит атакующего в вечной заморозке.
 * 🔴 Инвариант 3: одно открытое окно на ЗАЩИТНИКА — гарантирует UNIQUE по
 * генерируемой колонке `open_defender_id` (миграция `-01`), `open()` бьёт через
 * `ConditionalWriteService::insertUnique()`, второй атакующий получает честный
 * отказ (`null`), не исключение и не порчу чужой транзакции.
 * 🔴 Инвариант 4: переход статуса — только `transitionIfCurrent()` из `open`.
 */
final class PvpStandoffService
{
    private PvpStandoffModel $model;
    private ConditionalWriteService $writer;
    private GameSettingsService $settings;
    private DefenseStructureService $defense;

    public function __construct(
        ?PvpStandoffModel $model = null,
        ?ConditionalWriteService $writer = null,
        ?GameSettingsService $settings = null,
        ?DefenseStructureService $defense = null
    ) {
        $this->model    = $model ?? new PvpStandoffModel();
        $this->writer   = $writer ?? new ConditionalWriteService();
        $this->settings = $settings ?? new GameSettingsService();
        $this->defense  = $defense ?? new DefenseStructureService($this->settings);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('pvp.standoff.enabled', false);
    }

    /**
     * Включено, у защитника на этой клетке непустой набор активных построек
     * (ADR-186 §2 — НЕ `getDefenseProfile() !== null`, см. класс-докблок
     * {@see DefenseStructureService::hasActiveStructuresOnCell()}), `require_tower`
     * соблюдён (если килсвитч включён), и кулдаун защитника после прошлого окна истёк.
     */
    public function shouldOpen(int $defenderId, int $cellNumber): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! $this->defense->hasActiveStructuresOnCell($defenderId, $cellNumber)) {
            return false;
        }

        $requireTower = (bool) $this->settings->get('pvp.standoff.require_tower', false);
        if ($requireTower && ! $this->hasActiveTowerOnCell($defenderId, $cellNumber)) {
            return false;
        }

        if ($this->isDefenderOnCooldown($defenderId)) {
            return false;
        }

        return true;
    }

    /**
     * `insertUnique()` — при дубле (защитник уже под тревогой) возвращает `null`,
     * вызывающий обязан позвать {@see activeAgainst()} сам (ADR-186 §5: второй
     * атакующий наследует остаток, а не получает свежие пять минут).
     *
     * @return array<string,mixed>|null
     */
    public function open(int $attackerId, int $defenderId, int $cellNumber): ?array
    {
        $windowSec = max(1, (int) $this->settings->get('pvp.standoff.window_sec', 300));
        $now       = time();

        $row = [
            'attacker_id'      => $attackerId,
            'defender_id'      => $defenderId,
            'cell_number'      => $cellNumber,
            'started_at'       => date('Y-m-d H:i:s', $now),
            'expires_at'       => date('Y-m-d H:i:s', $now + $windowSec),
            'status'           => 'open',
            'notified_expired' => 0,
            'created_at'       => date('Y-m-d H:i:s', $now),
            'updated_at'       => date('Y-m-d H:i:s', $now),
        ];

        $outcome = $this->writer->insertUnique('pvp_standoffs', $row);
        if ($outcome !== WriteOutcome::Applied) {
            return null;
        }

        $opened = $this->activeFor($attackerId, $defenderId);
        if ($opened !== null) {
            $idRaw = $opened['id'] ?? 0;
            $this->auditStandoff(is_numeric($idRaw) ? (int) $idRaw : 0, $defenderId, 'pvp_standoff_opened');
        }

        return $opened;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function activeAgainst(int $defenderId): ?array
    {
        return $this->normalizeRow($this->model
            ->where('defender_id', $defenderId)
            ->where('status', 'open')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first());
    }

    /**
     * То же, суженное на пару (используется `open()` сразу после вставки, чтобы
     * вернуть именно СВОЮ строку, а не чужое открытое окно того же защитника).
     *
     * @return array<string,mixed>|null
     */
    public function activeFor(int $attackerId, int $defenderId): ?array
    {
        return $this->normalizeRow($this->model
            ->where('attacker_id', $attackerId)
            ->where('defender_id', $defenderId)
            ->where('status', 'open')
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first());
    }

    /**
     * `transitionIfCurrent()` со статуса `open` — `false` = «ты уже отреагировал»
     * (второй ход защитника подряд, или окно уже истекло и его закрыл крон).
     */
    public function close(int $standoffId, string $status): bool
    {
        $outcome = $this->writer->transitionIfCurrent('pvp_standoffs', $standoffId, 'status', 'open', $status);
        if ($outcome !== WriteOutcome::Applied) {
            return false;
        }

        // transitionIfCurrent() пишет только колонку `status` — стамп updated_at
        // (кулдаун защитника отсчитывается от него) обычным Model::update(), не
        // мешает гарантии выше: переход уже применён ровно один раз.
        $row = $this->normalizeRow($this->model->find($standoffId));
        if ($row !== null) {
            $this->model->update($standoffId, ['status' => $status]);
            $defenderIdRaw = $row['defender_id'] ?? 0;
            $this->auditStandoff($standoffId, is_numeric($defenderIdRaw) ? (int) $defenderIdRaw : 0, $this->auditCodeFor($status));
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function secondsLeft(array $row): int
    {
        $expiresAt = is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null;
        if ($expiresAt === null) {
            return 0;
        }
        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return 0;
        }
        return max(0, $ts - time());
    }

    /**
     * Одноразовый пинг атакующему об истечении окна — `notified_expired`
     * 0 → 1 через `transitionIfCurrent()`, чтобы два одновременных тика крона
     * не отправили пинг дважды.
     */
    public function markExpiryNotified(int $standoffId): bool
    {
        $outcome = $this->writer->transitionIfCurrent('pvp_standoffs', $standoffId, 'notified_expired', '0', '1');
        return $outcome === WriteOutcome::Applied;
    }

    /**
     * Кулдаун защитника после закрытия последнего его окна (ADR-186 §5,
     * `pvp.standoff.cooldown_sec`) — анти-эксплойт «чередующиеся атакующие держат
     * базу в вечной тревоге». Ищет самое свежее ЗАКРЫТОЕ окно этого защитника.
     */
    private function isDefenderOnCooldown(int $defenderId): bool
    {
        $cooldownSec = max(0, (int) $this->settings->get('pvp.standoff.cooldown_sec', 900));
        if ($cooldownSec <= 0) {
            return false;
        }

        $lastClosed = $this->normalizeRow((new PvpStandoffModel())
            ->where('defender_id', $defenderId)
            ->where('status !=', 'open')
            ->orderBy('updated_at', 'DESC')
            ->first());
        if ($lastClosed === null) {
            return false;
        }

        $closedAtRaw = is_string($lastClosed['updated_at'] ?? null) ? $lastClosed['updated_at'] : null;
        if ($closedAtRaw === null) {
            return false;
        }
        $closedAt = strtotime($closedAtRaw);
        if ($closedAt === false) {
            return false;
        }

        return ($closedAt + $cooldownSec) > time();
    }

    /**
     * `pvp.standoff.require_tower` (default false — ADR-186 §2): сужение признака
     * базы до конкретно Дозорной вышки, не второе определение «дома». Тот же join,
     * что {@see TowerAlertService::towersInBox()}, суженный на клетку вместо
     * bounding box.
     */
    private function hasActiveTowerOnCell(int $characterId, int $cellNumber): bool
    {
        try {
            $db    = Database::connect();
            $query = $db->table('character_buildings cb')
                ->select('cb.id')
                ->join('buildings b', 'b.id = cb.building_id')
                ->where('cb.character_id', $characterId)
                ->where('cb.map_cell_id', $cellNumber)
                ->where('cb.building_type', 'defensive')
                ->where('b.name_en', 'WatchTower')
                ->where('cb.hp >', 0)
                ->get();
            if ($query === false) {
                return false;
            }
            return $query->getRowArray() !== null;
        } catch (Throwable $e) {
            log_message('error', '[PvpStandoffService] hasActiveTowerOnCell failed: ' . $e->getMessage());
            return false;
        }
    }

    private function auditCodeFor(string $status): string
    {
        return match ($status) {
            'fled'      => 'pvp_standoff_fled',
            'countered' => 'pvp_standoff_countered',
            'held'      => 'pvp_standoff_held',
            'expired'   => 'pvp_standoff_expired',
            'cancelled' => 'pvp_standoff_cancelled',
            default     => 'pvp_standoff_' . $status,
        };
    }

    /**
     * CI4 `Model::first()`/`find()` типизируются `mixed` на уровне phpstan-стабов
     * шире реального `$returnType = 'array'` — приводим к точному
     * `array<string,mixed>|null` в одной точке (тот же приём, что
     * `CaravanModel::findActiveOnCell()`).
     *
     * @param mixed $row
     * @return array<string,mixed>|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    private function auditStandoff(int $standoffId, int $defenderId, string $actionName): void
    {
        try {
            (new ActionLogModel())->insert([
                'character_id'  => $defenderId,
                'chat_id'       => 0,
                'action_name'   => $actionName,
                'action_status' => 'Completed',
                'description'   => "Окно противостояния #{$standoffId}: {$actionName}",
            ]);
        } catch (Throwable $e) {
            log_message('error', '[PvpStandoffService] auditStandoff failed: ' . $e->getMessage());
        }
    }
}
