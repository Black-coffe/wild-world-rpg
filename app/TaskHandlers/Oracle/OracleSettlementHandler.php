<?php

declare(strict_types=1);

namespace App\TaskHandlers\Oracle;

use App\Attributes\HandlerKey;
use App\Services\Oracle\OracleService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * ADR-133 — крон «Оракул острова» (зеркало PvpLadderWeeklyBroadcastHandler).
 *
 * Tasks.php everyMinute + singleInstance. Каждый тик (при killswitch oracle.enabled):
 *   1. ensureMarkets()    — открыть рынки текущего цикла, если их нет (идемпотентно).
 *   2. lockDueMarkets()   — закрыть ставки у рынков, чьё locks_at наступило.
 *   3. settleDueMarkets() — рассчитать рынки, чьё resolves_at наступило (атомарный claim
 *      каждого рынка locked→settling внутри сервиса → гонка-сейф) и вернуть уведомления.
 *
 * Расчёт — пари-мютюэль: пул проигравших − сжигаемый рейк делится между угадавшими
 * (инвариант Σвыплат ≤ Σставок). При тонком/одностороннем пуле — void + полный возврат.
 * Рассылка результатов участникам через safeSendMessage (lazy Telegram-init, ловит ошибки).
 *
 * Dormant: при oracle.enabled=false сервис мгновенно выходит (ensureMarkets/lock/settle — no-op).
 */
#[HandlerKey(
    key: 'oracle_settlement',
    displayName: 'Оракул острова — открытие/закрытие/расчёт рынков (ADR-133)',
    description: 'everyMinute: открывает рынки цикла, закрывает ставки по locks_at, рассчитывает по resolves_at (пари-мютюэль, рейк сжигается). Killswitch oracle.enabled.',
)]
class OracleSettlementHandler extends BaseTaskHandler
{
    private const THROTTLE_USEC = 35000; // ~28 msg/sec < лимит Telegram 30/sec

    private OracleService $oracle;

    public function __construct(?OracleService $oracle = null)
    {
        $this->oracle = $oracle ?? new OracleService();
    }

    /**
     * @param array<int|string, mixed> $task
     */
    public function handle(array $task = []): void
    {
        if (! $this->oracle->enabled()) {
            return;
        }

        $this->oracle->ensureMarkets();
        $this->oracle->lockDueMarkets();

        $notifications = $this->oracle->settleDueMarkets();
        if ($notifications === []) {
            return;
        }

        $sent = 0;
        foreach ($notifications as $note) {
            $tgId = $note['telegram_id'];
            if ($tgId === null || $tgId === 0) {
                continue;
            }
            $this->sendNotification($tgId, $note['text']);
            $sent++;
            usleep(self::THROTTLE_USEC);
        }

        if ($sent > 0) {
            log_message('info', "[Oracle] settlement notifications sent: {$sent}");
        }
    }

    /**
     * Seam для тестов: реальная отправка. Переопределяется в db-тесте (без Telegram-init).
     */
    protected function sendNotification(int $tgId, string $text): void
    {
        $this->safeSendMessage($tgId, $text, ['parse_mode' => 'Markdown']);
    }
}
