<?php

declare(strict_types=1);

namespace App\TaskHandlers\PVP;

use App\Attributes\HandlerKey;
use App\Models\PvpStandoffModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\PvpStandoffService;
use App\Services\PVE\StandoffNotifier;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-186 §1/§4 (pvp-detection-clarity-10) — ленивое истечение окна противостояния.
 *
 * 🔴 Крон НЕ решает, истекло ли окно — это уже вычисляется по `expires_at` в момент
 * ЧТЕНИЯ ({@see PvpStandoffService::activeAgainst()}), безотносительно этого handler'а.
 * Здесь — только постфактум-уведомление и уборка: перевести просроченные `open` в
 * `expired` (через `transitionIfCurrent()` в `close()`, гонки исключены) и один раз
 * пингануть атакующего, что ждать больше нечего. Опоздавший крон делает сообщение
 * поздним, а не правило неверным — упавший крон НЕ держит атакующего в заморозке,
 * потому что заморозка и так снимается сама при чтении просроченной `expires_at`.
 *
 * everyMinute + singleInstance (Config\Tasks). Killswitch `pvp.standoff.enabled=false` —
 * handler выходит мгновенно, ни одной строки не читает. `pvp.standoff.notify_attacker_on_expiry`
 * (default true) гейтит только пинг: статус переводится в `expired` в любом случае, пинг —
 * только если ключ включён, и `notified_expired` не даёт отправить его дважды.
 */
#[HandlerKey(
    key: 'pvp_standoff_expiry',
    displayName: 'Окно противостояния: истечение + пинг атакующему (cron)',
    description: 'Recurring (everyMinute): переводит просроченные окна open→expired и один раз уведомляет атакующего. Killswitch pvp.standoff.enabled. ADR-186 §1/§4.',
)]
class StandoffExpiryHandler extends BaseTaskHandler
{
    private PvpStandoffModel $model;
    private PvpStandoffService $service;
    private StandoffNotifier $notifier;
    private GameSettingsService $settings;

    public function __construct(
        ?PvpStandoffModel $model = null,
        ?PvpStandoffService $service = null,
        ?StandoffNotifier $notifier = null,
        ?GameSettingsService $settings = null
    ) {
        $this->model    = $model ?? new PvpStandoffModel();
        $this->service  = $service ?? new PvpStandoffService();
        $this->notifier = $notifier ?? new StandoffNotifier();
        $this->settings = $settings ?? new GameSettingsService();
    }

    /**
     * @param array<string,mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->service->isEnabled()) {
            return;
        }

        // Просрочку решают часы БД, не часы PHP-процесса — ровно то же мерило времени,
        // что уже использует PvpStandoffService::activeAgainst() (`expires_at > NOW()`).
        // Подстановка строки из date() развела бы источники правды при дрейфе/разнице
        // таймзон между приложением и MySQL: крон закрыл бы окно, которое сервис ещё
        // считает открытым, или наоборот.
        $rows = $this->model
            ->where('status', 'open')
            ->where('expires_at <= NOW()', null, false)
            ->findAll();
        if ($rows === []) {
            return;
        }

        $notifyOnExpiry = (bool) $this->settings->get('pvp.standoff.notify_attacker_on_expiry', true);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $idRaw = $row['id'] ?? 0;
            $id    = is_numeric($idRaw) ? (int) $idRaw : 0;
            if ($id <= 0) {
                continue;
            }

            // transitionIfCurrent() внутри close() — единственная точка правды перехода:
            // если другой тик крона (или сам защитник) уже отреагировал, close() вернёт
            // false и этот ряд просто пропускается без побочных эффектов.
            if (! $this->service->close($id, 'expired')) {
                continue;
            }

            if (! $notifyOnExpiry) {
                continue;
            }

            // markExpiryNotified() — свой условный переход (0→1), не завязан на close():
            // именно он делает пинг одноразовым, а не факт закрытия окна.
            if ($this->service->markExpiryNotified($id)) {
                $this->notifier->notifyAttackerExpired($this->normalizeRow($row));
            }
        }
    }

    /**
     * Тот же приём, что `PvpStandoffService::normalizeRow()`: CI4 `Model::findAll()`
     * типизируется phpstan-стабами шире реального `$returnType = 'array'` — приводим
     * к точному `array<string,mixed>` в одной точке перед передачей в `StandoffNotifier`.
     *
     * @param array<int|string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
