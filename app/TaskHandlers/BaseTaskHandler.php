<?php

namespace App\TaskHandlers;

use App\TaskHandlers\Contracts\TaskHandlerInterface;
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Telegram;

/**
 * F2.9 — общий базовый класс task-handler'ов.
 *
 * Что даёт:
 *  - Инициализация Telegram-моста с lazy-getter — handler не платит
 *    стоимость `new Telegram()` если ничего не отправляет в чат.
 *  - Унифицированный `protected function safeSendMessage(...)` /
 *    `safeSendPhoto(...)` — ловят TelegramException, не дают handler'у
 *    упасть из-за rate-limit или сетевой ошибки.
 *  - Общий слот для DI зависимостей (`config('GameBalance')`, etc.)
 *    подключается один раз в `__construct`.
 *
 * Существующие handler'ы (~70 штук) пока его НЕ наследуют — они работают
 * и ничего трогать не нужно. Этот класс — для НОВЫХ handler'ов и для
 * ребилдов в F2.3/F2.7/F2.8.
 *
 * Сравни: `AttackPlayerAction` имеет 13 моделей в конструкторе —
 * BaseTaskHandler минимизирует boilerplate за счёт getters-on-demand.
 */
abstract class BaseTaskHandler implements TaskHandlerInterface
{
    private ?Telegram $telegram = null;

    /**
     * Lazy-getter Telegram-объекта. Не инициализируется пока не нужен —
     * экономит для handler'ов, которые только пишут в БД без отправки
     * сообщений (HealthRegenerationHandler, ResourceBankUpdateHandler,
     * etc.).
     */
    protected function telegram(): Telegram
    {
        if ($this->telegram === null) {
            $apiKey   = (string) getenv('telegram.API_KEY');
            $username = (string) getenv('telegram.BOT_USERNAME');
            try {
                $this->telegram = new Telegram($apiKey, $username);
                Request::initialize($this->telegram);
            } catch (TelegramException $e) {
                log_message('error', '[' . static::class . '] Telegram init: ' . $e->getMessage());
                // Возвращаем «пустышку», чтобы дальнейший код не падал.
                // safeSend* всё равно проверяет результат.
                $this->telegram = new Telegram('invalid', 'invalid');
            }
        }
        return $this->telegram;
    }

    /**
     * W28 (ADR-083) — помечает ли этот handler свои уведомления как РУТИННЫЕ
     * (завершение добычи/крафта/стройки/разведки). Рутинные при активном
     * killswitch `notifications.silent_threshold.enabled` доставляются тихо
     * (disable_notification=true). Default false — handler шлёт со звуком.
     *
     * Routine-handler'ы переопределяют это на `true` одной строкой; конкретные
     * send-call'ы трогать не нужно — решение принимается централизованно в
     * {@see safeSendMessage()} / {@see safeSendPhoto()}.
     */
    protected function isRoutineNotification(): bool
    {
        return false;
    }

    /**
     * W28 — подмешивает disable_notification=true в $extra, если уведомление
     * рутинное И policy разрешает тишину для этого чата И caller сам флаг не
     * задал. Иначе возвращает $extra без изменений (всегда со звуком).
     *
     * @param int|string          $chatId
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function applySilentThreshold($chatId, array $extra): array
    {
        if (! $this->isRoutineNotification()) {
            return $extra;
        }
        if (array_key_exists('disable_notification', $extra)) {
            return $extra; // caller уже решил — не перетираем
        }
        if (\App\Services\Notifications\SilentNotificationPolicy::routineSilentFor((int) $chatId)) {
            $extra['disable_notification'] = true;
        }

        return $extra;
    }

    /**
     * Безопасная отправка текста. Никогда не бросает наружу.
     *
     * @param int|string         $chatId
     * @param array<string,mixed> $extra доп.поля Telegram API
     */
    protected function safeSendMessage($chatId, string $text, array $extra = []): void
    {
        // Гарантируем что Telegram инициализирован.
        $this->telegram();
        // E6 Ф4 (ADR-108) — дедуп идентичных рутинных уведомлений в окне (dormant).
        if ($this->isRoutineNotification()
            && \App\Services\Notifications\NotificationDedupService::shouldSuppress((int) $chatId, $text)) {
            return;
        }
        $extra = $this->applySilentThreshold($chatId, $extra);
        try {
            $payload = array_merge([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ], $extra);
            $response = Request::sendMessage($payload);
            if (!$response->isOk()) {
                log_message('warning', '[' . static::class . '] Telegram sendMessage not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] sendMessage exception: ' . $e->getMessage());
        }
    }

    /**
     * Безопасная отправка фото с подписью — с деградацией на текст.
     *
     * Класс-баг «тихая потеря доставки» (аудит 2026-07-10, B2/C4): при отсутствии файла
     * `Request::encodeFile()` бросал fopen-исключение ДО `MediaSender`, а catch лишь логировал
     * → уведомление не доходило даже текстом. Картинка — enhancement (MEDIA-OFF, ADR-020):
     * если её нельзя доставить, caption обязан уйти обычным сообщением, весь смысл (лут/числа/
     * инструкции) доходит. Деградация во ВСЕХ трёх точках потери:
     *   1) файла точно нет (пред-проверка `is_file`) → сразу текст, не трогаем encodeFile;
     *   2) encodeFile/транспорт бросили (catch) → текст;
     *   3) Telegram отклонил фото, `ok=false` (битый/не-того-формата файл) → текст.
     * `MediaSender::sendPhotoOrText` свои деградации (media-off #14, caption>1024) отдаёт с
     * `ok=true`, поэтому ветка (3) ловит только настоящий провал фото — задвоения доставки нет.
     *
     * @param int|string $chatId
     * @param string $photoPath абсолютный локальный путь или URL нашего сайта (base_url)
     * @param array<string,mixed> $extra несёт parse_mode/reply_markup caller'а — они переходят в текст
     */
    protected function safeSendPhoto($chatId, string $photoPath, string $caption = '', array $extra = []): void
    {
        $this->telegram();
        // E6 Ф4 (ADR-108) — дедуп идентичных рутинных уведомлений в окне (dormant).
        if ($this->isRoutineNotification()
            && \App\Services\Notifications\NotificationDedupService::shouldSuppress((int) $chatId, $caption)) {
            return;
        }
        $extra = $this->applySilentThreshold($chatId, $extra);

        [$encodeTarget, $definitelyMissing] = $this->resolveLocalPhoto($photoPath);

        // (1) Файла точно нет → не зовём encodeFile (fopen бросит), сразу текст.
        if ($definitelyMissing) {
            log_message('warning', '[' . static::class . '] photo missing, degrading to text: ' . $photoPath);
            $this->degradeToText($chatId, $caption, $extra);
            return;
        }

        try {
            $payload = array_merge([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($encodeTarget),
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ], $extra);
            $response = \App\Services\Notifications\MediaSender::sendPhotoOrText($payload);
            if (!$response->isOk()) {
                // (3) Реальный провал фото (media-off/длинный caption MediaSender отдаёт ok=true).
                log_message('warning', '[' . static::class . '] Telegram sendPhoto not ok, degrading to text: '
                    . $response->getDescription());
                $this->degradeToText($chatId, $caption, $extra);
            }
        } catch (\Throwable $e) {
            // (2) encodeFile fopen / транспорт бросили.
            log_message('error', '[' . static::class . '] sendPhoto exception, degrading to text: ' . $e->getMessage());
            $this->degradeToText($chatId, $caption, $extra);
        }
    }

    /**
     * Резолвит цель для `encodeFile` и говорит, ТОЧНО ли локальный файл отсутствует.
     * Callers шлют два вида пути, оба указывают на наш `public/`: абсолютный `FCPATH.путь`
     * ЛИБО `base_url('uploads/...')`. URL нашего сайта мапится в локальный путь (надёжнее
     * HTTP-fetch).
     *
     * 🔒 Path-traversal guard (security-review 2026-07-10, HIGH): цель `encodeFile` уходит в
     * `fopen` и её содержимое отправляется в чат. Без канонизации путь с `..` (например
     * `base_url('uploads/../../.env')` или `FCPATH.'uploads/../../app/Config/...'`) прочитал
     * бы произвольный файл вне webroot → эксфильтрация. Сейчас все callers шлют конфиг/БД-пути,
     * не пользовательский ввод, но это `protected`-метод базы ~70 хендлеров — конфайним жёстко:
     * канонизируем `realpath` и требуем, чтобы файл лежал под `public/uploads`. Любой выход за
     * границу (traversal / симлинк наружу / абсолютный путь к секрету) → «отсутствует» →
     * деградация на текст. `realpath()` даёт `false` для несуществующего пути — это и есть
     * штатное «файла нет». Чужой URL не фетчим (анти-SSRF) → тоже текст.
     *
     * @return array{0:string,1:bool} [цель encodeFile, файл точно отсутствует]
     */
    protected function resolveLocalPhoto(string $photoPath): array
    {
        if ($photoPath === '') {
            return [$photoPath, true];
        }

        // URL нашего сайта → мапим в локальный путь. Чужой URL не фетчим (анти-SSRF) → текст.
        $candidate = $photoPath;
        if (preg_match('#^https?://#i', $photoPath) === 1) {
            $base = rtrim(base_url(), '/');
            if (! str_starts_with($photoPath, $base . '/')) {
                return [$photoPath, true];
            }
            $rel       = ltrim(substr($photoPath, strlen($base)), '/');
            $candidate = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }

        // Канонизация + конфайнмент к public/uploads. Отсекает `..`, симлинки наружу и
        // абсолютные пути к секретам (.env / app / writable живут ВНЕ public).
        $real    = realpath($candidate);
        $allowed = realpath(FCPATH . 'uploads');
        if ($real === false || $allowed === false
            || strncmp($real, $allowed . DIRECTORY_SEPARATOR, strlen($allowed) + 1) !== 0) {
            return [$photoPath, true];
        }

        return [$real, false];
    }

    /**
     * Деградация фото→текст: caption уходит обычным сообщением, когда картинку доставить
     * нельзя. `$extra` уже прошёл {@see applySilentThreshold()} и несёт `parse_mode` /
     * `reply_markup` caller'а — разметка и inline-кнопки сохраняются (важно для ивент-
     * бродкастов с клавиатурой). Пустой caption → placeholder (как в MediaSender).
     *
     * @param int|string $chatId
     * @param array<string,mixed> $extra
     */
    private function degradeToText($chatId, string $caption, array $extra): void
    {
        $text = $caption !== '' ? $caption : '📭 (без описания)';
        // Telegram-лимит текста 4096 (caption фото ≤1024, так что обрезка почти никогда не нужна).
        if (mb_strlen($text) > 4096) {
            $text = mb_substr($text, 0, 4093) . '…';
        }

        try {
            $payload = array_merge([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ], $extra);
            $response = Request::sendMessage($payload);
            if (!$response->isOk()) {
                log_message('warning', '[' . static::class . '] degradeToText not ok: '
                    . $response->getDescription());
            }
        } catch (\Throwable $e) {
            log_message('error', '[' . static::class . '] degradeToText exception: ' . $e->getMessage());
        }
    }

    /**
     * Контракт `TaskHandlerInterface::handle`. Конкретные handler'ы
     * переопределяют этот метод.
     */
    abstract public function handle(array $task = []): void;
}
