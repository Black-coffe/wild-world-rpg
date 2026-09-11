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
    /**
     * pvp-detection-clarity-27 — горизонт РЕТРАЯ провалившегося пинга, не длительность
     * самого окна противостояния (то `expires_at`, свой параметр). Пинг «окно истекло —
     * атакуй» ценен только пока атакующий ещё может тут же ударить: реакция защитника
     * (`alertDefender()`) даёт ему три хода прямо сейчас, а не через час. Без горизонта
     * повторная попытка держала бы строку в наборе на каждом тике сколь угодно долго —
     * это бьёт по двум сценариям: (1) ключ `notify_attacker_on_expiry` выключили на время
     * и включили обратно — первый тик после включения разослал бы пинги про окна давней
     * давности живым игрокам ровно в момент, когда владелец включает фичу и смотрит на
     * неё; (2) атакующий заблокировал бота — его строка перевыбиралась бы вечно. 10 минут
     * взяты по аналогии с `pvp.standoff.cooldown_sec` (дефолт 900 сек, ADR-186 §5) —
     * тот же порядок величины «скоро неактуально», но короче: там кулдаун защитника,
     * здесь окно, где пинг ещё что-то меняет для атакующего.
     */
    private const RETRY_HORIZON_SEC = 600;

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
        //
        // pvp-detection-clarity-27 — вторая группа условий добирает строки, которые уже
        // закрыты (`expired`), но пинг по ним провалился и `notified_expired` остался 0:
        // без неё провалившийся пинг терял окно навсегда, потому что `close()` меняет
        // `status` на `expired` и первая группа (`status = 'open'`) её больше не видит.
        // Гейтим её тем же флагом, что и саму отправку: при выключенном
        // `notify_attacker_on_expiry` `notified_expired` никогда не станет 1 (пинг не
        // шлём), и без гейта запрос перечитывал бы РАСТУЩИЙ набор всех expired-строк
        // за всё время на каждом тике — а не только те, что реально ждут повторной
        // попытки доставки. Второе ограничение — RETRY_HORIZON_SEC: без него та же
        // группа собрала бы недельной давности строки в момент, когда ключ выключили
        // и снова включили (мусорная рассылка живым игрокам), и держала бы вечно
        // строку с постоянно падающей отправкой (например, атакующий заблокировал бота).
        $notifyOnExpiry = (bool) $this->settings->get('pvp.standoff.notify_attacker_on_expiry', true);

        $builder = $this->model->groupStart()
                ->where('status', 'open')
                ->where('expires_at <= NOW()', null, false)
            ->groupEnd();
        if ($notifyOnExpiry) {
            $builder = $builder->orGroupStart()
                    ->where('status', 'expired')
                    ->where('notified_expired', 0)
                    ->where(
                        'expires_at > NOW() - INTERVAL ' . self::RETRY_HORIZON_SEC . ' SECOND',
                        null,
                        false
                    )
                ->groupEnd();
        }
        $rows = $builder->findAll();
        if ($rows === []) {
            return;
        }

        // pvp-detection-clarity-27 — этот handler исполняется в CLI-процессе
        // `spark tasks:run`, где Telegram-мост никем не инициализирован. Соседние
        // крон-хендлеры (DailyTipBroadcastHandler и т.п.) идут через
        // safeSendMessage(), который сам зовёт telegram() лениво; StandoffNotifier —
        // сервис общего назначения (его зовут ещё и из webhook, где мост уже готов),
        // поэтому инициализацию делаем здесь, один раз на прогон, до первой отправки.
        //
        // pvp-detection-clarity-27 (CI-находка) — `BaseTaskHandler::telegram()` ловит
        // TelegramException только вокруг ПЕРВОЙ попытки; аварийная ветка сама создаёт
        // `new Telegram('invalid','invalid')`, а конструктор `Telegram` бросает то же
        // исключение на невалидном ключе/окружении без ключа (как на CI) — «страховка»
        // не спасает. Оборачиваем здесь, а не правим общий `BaseTaskHandler` (он вне
        // `## Files` этой story и его авария задевает разом все ~70 handler'ов). Если
        // мост не поднялся — гасим ТОЛЬКО рассылку пингов в этом тике; перевод
        // просроченных `open` в `expired` ниже не зависит от Telegram и обязан пройти
        // в любом случае. Строки останутся с `notified_expired=0` и попадут под ретрай
        // следующего тика в пределах RETRY_HORIZON_SEC — ровно желаемое поведение.
        if ($notifyOnExpiry) {
            try {
                $this->telegram();
            } catch (\Throwable $e) {
                log_message('error', '[' . static::class . '] telegram() init failed, skipping pings this tick: ' . $e->getMessage());
                $notifyOnExpiry = false;
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $idRaw = $row['id'] ?? 0;
            $id    = is_numeric($idRaw) ? (int) $idRaw : 0;
            if ($id <= 0) {
                continue;
            }

            $statusRaw = $row['status'] ?? '';
            $status    = is_string($statusRaw) ? $statusRaw : '';

            // pvp-detection-clarity-27 — строка попадает сюда либо ещё открытой
            // (нужно закрыть), либо уже `expired` из прошлого тика, у которого пинг
            // провалился (закрывать второй раз не нужно, close() всё равно бы вернул
            // false — transitionIfCurrent() ждёт старое значение 'open').
            if ($status === 'open') {
                // transitionIfCurrent() внутри close() — единственная точка правды
                // перехода: если другой тик крона (или сам защитник) уже отреагировал,
                // close() вернёт false и этот ряд просто пропускается без побочных эффектов.
                if (! $this->service->close($id, 'expired')) {
                    continue;
                }
            }

            if (! $notifyOnExpiry) {
                continue;
            }

            // pvp-detection-clarity-27 — порядок перевёрнут: раньше флаг сжигался ДО
            // отправки, и провалившийся пинг терял окно навсегда (следующий тик его
            // уже не брал). Теперь markExpiryNotified() (свой условный переход 0→1)
            // ставится только когда notifier реально сообщил об успехе — провал
            // оставляет строку доступной следующему тику.
            if ($this->notifier->notifyAttackerExpired($this->normalizeRow($row))) {
                $this->service->markExpiryNotified($id);
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
