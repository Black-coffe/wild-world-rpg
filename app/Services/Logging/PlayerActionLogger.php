<?php

declare(strict_types=1);

namespace App\Services\Logging;

use App\Models\CharacterModel;
use App\Models\PlayerActionLogModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-148 — централизованный логгер ВСЕХ прямых действий игрока (firehose).
 *
 * Request-scoped: один Telegram-апдейт = одно действие игрока, поэтому захват живёт на время
 * обработки апдейта. Жизненный цикл (в {@see \App\Controllers\Telegram\BotController::webhook()}):
 *
 *   PlayerActionLogger::current()->begin($update);   // распарсить «что/кто/откуда»
 *   ... $telegram->handle() (внутри — диспетчеры/хендлеры) ...
 *       └ глубокие аннотации (не обязательны для базового захвата):
 *           ::current()->markUnrouted()   — мёртвая кнопка / «не понял команду»
 *           ::current()->markRejected($r) — бизнес-отказ (BaseAction::logRejected)
 *           ::current()->markError($msg)  — исключение
 *   PlayerActionLogger::current()->commit();          // записать строку (в finally)
 *
 * 🔴 Defensive: ни один метод НЕ бросает наружу и НЕ должен влиять на обработку апдейта.
 * commit() — no-op, если begin() не активировал захват (не-player апдейт), killswitch
 * выключен или строка уже записана.
 *
 * НЕ final — тесты переопределяют seam'ы (enabled/insertRow/resolveCharacterId) анонимным
 * наследником (DB-free CI, паттерн NewbieGreeterService).
 *
 * @see \App\Models\PlayerActionLogModel
 */
class PlayerActionLogger
{
    /** Killswitch firehose в GameSettings (cached 60s; default ON). */
    public const KILLSWITCH = 'logging.player_actions.enabled';

    /** @var list<string> */
    private const VALID_SOURCES = ['callback', 'command', 'text', 'forcereply', 'other', 'task'];

    /** @var list<string> */
    private const VALID_STATUSES = ['ok', 'error', 'rejected', 'unrouted', 'undelivered'];

    private static ?self $current = null;

    private bool $active        = false;
    private bool $committed     = false;
    private ?int $telegramUserId = null;
    private ?int $chatId        = null;
    private string $source      = 'other';
    private string $actionName  = '';
    private string $rawInput    = '';
    private string $status      = 'ok';
    private ?string $errorText  = null;
    /** ADR-168 — с какого экрана нажата кнопка (метка снята в BotController). */
    private ?string $origin     = null;

    /**
     * Окно доставки: счётчики отправок (заполняет {@see TelegramDeliveryProbe}).
     * Открывается отдельно от `active`, потому что у Worker'а захвата begin/commit нет,
     * а окно нужно СВОЁ на каждую задачу — один прогон завершает их N.
     */
    private bool $deliveryWindow = false;
    private int $sendsOk         = 0;
    private int $sendsFailed     = 0;
    private ?string $sendError   = null;

    /** Request-scoped singleton (общий для BotController и глубоких аннотаций). */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /** Сброс singleton (тесты / гигиена). */
    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Начать захват действия из сырого Telegram-update. Логируем только player-апдейты
     * (callback_query | message); прочее (channel_post, my_chat_member, edited_message …)
     * пропускаем (active=false → commit() no-op).
     *
     * @param array<array-key,mixed> $update сырой JSON-decoded апдейт (ключи как из json)
     */
    public function begin(array $update): void
    {
        // Свежее состояние на каждый апдейт (на случай переиспользования singleton).
        $this->active         = false;
        $this->committed      = false;
        $this->status         = 'ok';
        $this->errorText      = null;
        $this->source         = 'other';
        $this->actionName     = '';
        $this->rawInput       = '';
        $this->telegramUserId = null;
        $this->chatId         = null;
        $this->origin         = null;
        $this->openDeliveryWindow();

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $cq   = $update['callback_query'];
            $data = is_string($cq['data'] ?? null) ? $cq['data'] : '';

            $this->source         = 'callback';
            $this->rawInput       = $data;
            $this->actionName     = $data === '' ? '(empty)' : explode('_', $data)[0];
            $this->telegramUserId = self::intOrNull(self::dig($cq, ['from', 'id']));
            $this->chatId         = self::intOrNull(self::dig($cq, ['message', 'chat', 'id']));
            $this->active         = true;
            return;
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $m    = $update['message'];
            $text = is_string($m['text'] ?? null) ? trim($m['text']) : '';

            $this->telegramUserId = self::intOrNull(self::dig($m, ['from', 'id']));
            $this->chatId         = self::intOrNull(self::dig($m, ['chat', 'id']));

            if ($text !== '' && $text[0] === '/') {
                // /start@bot args → команда 'start'
                $firstToken = explode(' ', $text)[0];
                $cmd        = explode('@', ltrim($firstToken, '/'))[0];
                $this->source     = 'command';
                $this->actionName = $cmd === '' ? '(slash)' : mb_strtolower($cmd);
                $this->rawInput   = $text;
            } else {
                $this->source     = isset($m['reply_to_message']) ? 'forcereply' : 'text';
                $this->rawInput   = $text === '' ? '(non-text)' : $text;
                $this->actionName = $text === '' ? '(non-text)' : mb_strtolower($text);
            }
            $this->active = true;
            return;
        }

        // Не player-апдейт — не логируем.
    }

    /**
     * ADR-168 — источник нажатия (с какого экрана пришла кнопка). Ставится в
     * {@see \App\Controllers\Telegram\BotController::webhook()} сразу после {@see begin()}
     * (тот сбрасывает состояние захвата). Пишется в отдельную колонку `origin`, а не в
     * `raw_input`: тот обязан остаться сравнимым с историей замеров до ADR-168.
     */
    public function setOrigin(?string $origin): void
    {
        if ($this->active) {
            $this->origin = ($origin === null || $origin === '') ? null : mb_substr($origin, 0, 16);
        }
    }

    /** Мёртвая/устаревшая кнопка или «не понял команду». Не перекрывает error. */
    public function markUnrouted(): void
    {
        if ($this->active && $this->status === 'ok') {
            $this->status = 'unrouted';
        }
    }

    /** Бизнес-отказ действия (нехватка ресурсов, гейт уровня и т.п.). */
    public function markRejected(?string $reason = null): void
    {
        if (! $this->active) {
            return;
        }
        if ($this->status !== 'error') { // error — сильнее, не понижаем
            $this->status = 'rejected';
        }
        if ($reason !== null && $this->errorText === null) {
            $this->errorText = mb_substr($reason, 0, 500);
        }
    }

    /**
     * Открыть НОВОЕ окно доставки (счётчики с нуля).
     *
     * Вебхук делает это внутри {@see begin()} — там окно = один апдейт. Worker обязан
     * открывать окно ПЕРЕД каждой задачей: прогон завершает N задач подряд в одном процессе,
     * и без сброса провал первой приписался бы всем следующим.
     */
    public function openDeliveryWindow(): void
    {
        $this->deliveryWindow = true;
        $this->sendsOk        = 0;
        $this->sendsFailed    = 0;
        $this->sendError      = null;
    }

    /**
     * Итог окна доставки: текст провала, если НИ ОДНА отправка не прошла при наличии провалов,
     * иначе `null` (доставили либо не отправляли вовсе).
     *
     * Для Worker'а: у фоновых завершений нет begin/commit, поэтому вердикт им нужен явным
     * значением — {@see recordTaskCompletion()} пишет строку сам.
     */
    public function deliveryFailureText(): ?string
    {
        if ($this->sendsOk > 0 || $this->sendsFailed === 0) {
            return null;
        }

        return mb_substr('не доставлено — ' . ($this->sendError ?? 'отправка не удалась'), 0, 500);
    }

    /**
     * Отметить исход ОДНОЙ отправки в Telegram (зовёт {@see TelegramDeliveryProbe}).
     *
     * 🔴 Вердикт здесь НЕ выносится: провал одной отправки — норма (`MediaSender::editOrSend`
     * штатно роняет правку и вытягивает фолбэком). Копим счётчики, а решение
     * «игрок не получил ответа» принимает {@see commit()}: ни одной успешной при наличии
     * провалов.
     */
    public function noteDelivery(bool $ok, string $method, ?string $description = null): void
    {
        if (! $this->deliveryWindow) {
            return;
        }

        if ($ok) {
            $this->sendsOk++;

            return;
        }

        $this->sendsFailed++;
        // Держим ПОСЛЕДНЮЮ ошибку: она ближе всего к тому, что игрок так и не увидел.
        $this->sendError = mb_substr(trim($method . ': ' . ($description ?? 'ok=false')), 0, 200);
    }

    /** Действие упало с исключением. Наивысший приоритет статуса. */
    public function markError(?string $message = null): void
    {
        if (! $this->active) {
            return;
        }
        $this->status = 'error';
        if ($message !== null && $message !== '') {
            $this->errorText = mb_substr($message, 0, 500);
        }
    }

    /**
     * Записать собранную строку firehose. 🔴 Defensive: никогда не бросает.
     */
    public function commit(): void
    {
        if (! $this->active || $this->committed) {
            return;
        }
        $this->committed = true;

        try {
            if (! $this->enabled()) {
                return;
            }

            $status = in_array($this->status, self::VALID_STATUSES, true) ? $this->status : 'ok';
            $source = in_array($this->source, self::VALID_SOURCES, true) ? $this->source : 'other';

            // Сигнал доставки: игрок нажал — ответа не ушло. Ставим, только если НИ ОДНА
            // отправка не прошла (провал одной при удачном фолбэке — норма, не тревога).
            // 'error' не понижаем: исключение объясняет причину точнее.
            $deliveryFailure = $this->deliveryFailureText();
            if ($status !== 'error' && $deliveryFailure !== null) {
                $status          = 'undelivered';
                $this->errorText = $this->composeUndeliveredText($deliveryFailure);
            }

            $this->insertRow([
                'character_id'     => $this->resolveCharacterId($this->telegramUserId),
                'telegram_user_id' => $this->telegramUserId,
                'chat_id'          => $this->chatId,
                'source'           => $source,
                'action_name'      => $this->actionName === '' ? null : mb_substr($this->actionName, 0, 255),
                'raw_input'        => $this->rawInput === '' ? null : mb_substr($this->rawInput, 0, 255),
                'status'           => $status,
                'error_text'       => $this->errorText,
                'origin'           => $this->origin,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[PlayerActionLogger] commit failed: ' . $e->getMessage());
        }
    }

    /**
     * ADR-148 (расширение охвата) — записать ЗАВЕРШЕНИЕ фоновой задачи игрока (Worker:
     * добыча/крафт/стройка/поход/ремонт/…). Самостоятельная вставка, НЕ связана с
     * request-scoped begin/commit: один прогон Worker'а завершает N задач → N строк.
     * source='task', status: 'ok' (хендлер отработал) / 'error' (хендлер упал).
     *
     * 🔴 Defensive: не бросает, не валит тик Worker'а (вызывается внутри try/catch claim'а).
     * character_id известен из character_tasks = надёжная связь; telegram_user_id/chat_id НЕ
     * пишем (в character_tasks telegram_user_id = внутренний users.id, не raw — не мешаем
     * семантику колонки firehose).
     */
    public function recordTaskCompletion(?int $characterId, string $taskName, string $status, ?string $errorText = null): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }
            $st = in_array($status, self::VALID_STATUSES, true) ? $status : 'ok';
            $this->insertRow([
                'character_id'     => $characterId,
                'telegram_user_id' => null,
                'chat_id'          => null,
                'source'           => 'task',
                'action_name'      => $taskName === '' ? null : mb_substr($taskName, 0, 255),
                'raw_input'        => null,
                'status'           => $st,
                'error_text'       => ($errorText !== null && $errorText !== '') ? mb_substr($errorText, 0, 500) : null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[PlayerActionLogger] recordTaskCompletion failed: ' . $e->getMessage());
        }
    }

    /**
     * Текст для статуса 'undelivered'. Прежняя причина (например, бизнес-отказ) не теряется —
     * к ней дописывается, что именно не доехало.
     */
    private function composeUndeliveredText(string $deliveryFailure): string
    {
        $text = ($this->errorText === null || $this->errorText === '')
            ? $deliveryFailure
            : $this->errorText . ' | ' . $deliveryFailure;

        return mb_substr($text, 0, 500);
    }

    /** Текущий статус (для тестов/диагностики). */
    public function status(): string
    {
        return $this->status;
    }

    /** Текущий источник нажатия (для тестов/диагностики). */
    public function origin(): ?string
    {
        return $this->origin;
    }

    /**
     * Включён ли firehose. Публично — чтобы {@see TelegramDeliveryProbe} не подменял
     * Guzzle-клиент Longman'а, когда телеметрия всё равно выключена (один аварийный
     * выключатель на оба механизма).
     */
    public function firehoseEnabled(): bool
    {
        try {
            return $this->enabled();
        } catch (\Throwable $e) {
            log_message('error', '[PlayerActionLogger] firehoseEnabled failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Активен ли захват (для тестов/диагностики). */
    public function isActive(): bool
    {
        return $this->active;
    }

    // ── seam'ы (переопределяются в тестах) ───────────────────────────────

    protected function enabled(): bool
    {
        $raw = (new GameSettingsService())->get(self::KILLSWITCH, true);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        return true;
    }

    /** @param array<string, scalar|null> $data */
    protected function insertRow(array $data): void
    {
        (new PlayerActionLogModel())->insert($data);
    }

    /**
     * telegram_id → telegram_users.id → characters.id (или null). 🔴 Defensive: любая ошибка
     * резолва (недоступная таблица и т.п.) → null, а НЕ потеря всей строки firehose. character_id
     * вычисляется как аргумент insertRow(), поэтому исключение здесь утопило бы запись целиком.
     */
    protected function resolveCharacterId(?int $telegramUserId): ?int
    {
        if ($telegramUserId === null) {
            return null;
        }
        try {
            $user = (new TelegramUserModel())->asArray()->where('telegram_id', $telegramUserId)->first();
            if (! is_array($user) || ! isset($user['id'])) {
                return null;
            }
            $char = (new CharacterModel())->asArray()->where('telegram_user_id', $user['id'])->first();
            if (! is_array($char) || ! isset($char['id']) || ! is_numeric($char['id'])) {
                return null;
            }
            return (int) $char['id'];
        } catch (\Throwable $e) {
            log_message('error', '[PlayerActionLogger] resolveCharacterId failed: ' . $e->getMessage());
            return null;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Достать вложенное значение из массива по пути ключей.
     *
     * @param array<array-key,mixed> $arr
     * @param list<string>           $path
     */
    private static function dig(array $arr, array $path): mixed
    {
        $cur = $arr;
        foreach ($path as $key) {
            if (! is_array($cur) || ! array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
