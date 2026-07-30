<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;

/**
 * F1.5 — rate-limit для входящего Telegram-webhook'а.
 *
 * Защита от:
 *   - бот-ускорителей (один игрок шлёт callback'и сотнями в секунду)
 *   - DDoS Worker'а через спам callback-нажатий
 *   - read/write race condition нескольких быстрых нажатий одной кнопки
 *
 * Алгоритм: для каждого update'а извлекаем from.id (telegram user id) и считаем
 * сколько апдейтов пришло за текущее окно. Если больше лимита — отвечаем 200 OK
 * с пустым body (Telegram доволен и не шлёт повтор) и сообщаем игроку, что он
 * упёрся в лимит.
 *
 * 🔴 Окно живёт В ЗНАЧЕНИИ (`start`), а НЕ в TTL ключа. Так было не всегда:
 * прежняя версия опиралась на `$cache->increment()`, а CI4 FileHandler внутри
 * делает `save($key, $value, $ttl)`, и `save()` перезаписывает `time => now`.
 * То есть TTL продлевался на КАЖДОМ тапе, а счётчик — нет: окно не закрывалось,
 * пока игрок тапал чаще раза в минуту. Правило фактически звучало как «30
 * действий за всю непрерывную сессию игры». Замер на проде 2026-07-30 (7 дней,
 * `player_action_log`): 340 срабатываний у 26 из 63 активных игроков, и лишь 15
 * из 340 (4.4%) были настоящим спамом (<60 сек на 30 тапов) — остальные 95.6%
 * поймали обычную игру со средней скоростью 14 тапов/мин. Типичная жертва:
 * 30 тапов за 3 мин 31 сек (ходил по базе и крафту), дальше — тишина до минуты.
 *
 * Хранилище — стандартный CI4 cache. На текущей конфигурации это file-cache,
 * на проде с Redis (фаза F3) сразу заработает корректно из-за shared store.
 *
 * Тонкости:
 *   - Limit применяется ТОЛЬКО к /telegram/webhook, не к admin-страницам.
 *   - Считаются и inline-кнопки (callback_query), и тапы по reply-клавиатуре
 *     (message) — для игрока это одинаковые «кнопки».
 *   - Если в payload нет from.id (channel post, edited_message без отправителя)
 *     — пропускаем без счётчика.
 *   - Возвращаем 200 OK (а не 429), чтобы Telegram не делал retry-bursts.
 *   - Один ключ на игрока (а не ключ-на-минуту): file-cache в CI4 не делает GC,
 *     ключи с минутным бакетом копили бы мусор в writable/cache без уборки.
 *   - read→write не атомарен. Для анти-абьюз-гейта это допустимо (Telegram шлёт
 *     апдейты одного чата последовательно, потеря единичного инкремента при
 *     гонке безобидна); прежняя реализация тоже была не атомарной.
 */
class TelegramRateLimitFilter implements FilterInterface
{
    /**
     * Максимум апдейтов в минуту на одного telegram-юзера.
     *
     * 60/мин = тап в секунду. Замер реального поведения (выше) даёт пики живой
     * игры до ~20 тапов/мин, так что запас — трёхкратный, а бот-ускоритель
     * (сотни запросов в секунду) режется по-прежнему. Переопределяется через
     * `telegram.RATE_LIMIT_PER_MINUTE` в .env — тюнинг без деплоя.
     *
     * Намеренно НЕ в GameSettings: это анти-абьюз-инфраструктура, а не игровой
     * баланс (CLAUDE.md §admin-tunable — «архитектурные timeouts → Config\* ok»),
     * и лезть в БД на каждом входящем апдейте ради него не стоит.
     */
    private const DEFAULT_MAX_PER_MINUTE = 60;

    /** Длина окна в секундах. */
    private const WINDOW_SECONDS = 60;

    /** TTL ключа: с запасом больше окна, чтобы запись пережила своё окно. */
    private const KEY_TTL_SECONDS = 120;

    public function before(RequestInterface $request, $arguments = null)
    {
        $body = $request->getBody();
        if (empty($body)) {
            return; // нечего смотреть
        }

        $update = json_decode($body, true);
        if (!is_array($update)) {
            return;
        }

        $userId = $this->extractUserId($update);
        if ($userId === null) {
            return; // системный update без отправителя — не считаем
        }

        $cache = \Config\Services::cache();
        $key   = "tg_rate_{$userId}";
        // Time::now() (а не time()) — тот же источник времени, что у CI4-кэша,
        // и он мокается через Time::setTestNow() в тестах окна.
        $now   = Time::now()->getTimestamp();
        $limit = $this->maxPerMinute();

        $state = $cache->get($key);
        // Не-массив = либо пусто, либо legacy-значение (голый int от старой версии).
        if (!is_array($state) || !isset($state['start'], $state['count'])) {
            $state = ['start' => $now, 'count' => 0, 'notified' => false];
        }

        $start = is_numeric($state['start']) ? (int) $state['start'] : $now;
        if ($now - $start >= self::WINDOW_SECONDS) {
            // Окно закрылось — начинаем новое. Именно этого не происходило раньше.
            $state = ['start' => $now, 'count' => 0, 'notified' => false];
        }

        $count = (is_numeric($state['count']) ? (int) $state['count'] : 0) + 1;

        if ($count > $limit) {
            // Счётчик дальше не растим (иначе бот-спам раздувал бы его вечно),
            // но фиксируем, что игрок уже предупреждён — чтобы не слать повторы.
            $alreadyNotified = !empty($state['notified']);
            $state['notified'] = true;
            $cache->save($key, $state, self::KEY_TTL_SECONDS);

            if (!$alreadyNotified) {
                // 'error', а не 'warning': на проде Logger threshold = 4 (Config/Logger.php),
                // warning (5) в лог НЕ попадает — прежние срабатывания были невидимы.
                //
                // Одна строка на ОКНО, а не на отброшенный апдейт: под флудом в 1000
                // запросов в минуту логирование каждого забило бы диск. Окно — и есть
                // нужная единица измерения («сколько раз игроки упирались в лимит»).
                log_message('error', "[RateLimit] Telegram user {$userId} превысил {$limit}/мин — апдейты отбрасываются до конца окна");
            }

            $this->notifyBlocked($update, $alreadyNotified);

            // 200 OK с пустым body — Telegram не делает retry, просто получает ack
            return service('response')->setStatusCode(200)->setBody('');
        }

        $state['count'] = $count;
        $cache->save($key, $state, self::KEY_TTL_SECONDS);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // не трогаем response
    }

    /** Лимит из .env с фолбэком на константу. Мусорные значения игнорируются. */
    private function maxPerMinute(): int
    {
        $raw = getenv('telegram.RATE_LIMIT_PER_MINUTE');
        if (is_string($raw) && ctype_digit(trim($raw)) && (int) trim($raw) > 0) {
            return (int) trim($raw);
        }

        return self::DEFAULT_MAX_PER_MINUTE;
    }

    /**
     * Сообщает игроку, что он упёрся в лимит. Без этого отброшенный апдейт
     * выглядит как сломанная кнопка: тап есть, реакции нет.
     *
     * - inline-кнопка → answerCallbackQuery (всплывашка, чат не засоряется);
     * - тап по reply-клавиатуре → обычное сообщение.
     *
     * 🔴 РОВНО ОДНО уведомление на окно (флаг `notified`). Иначе фильтр сам себя
     * превращает в усилитель: бот, шлющий 1000 запросов в минуту, получал бы
     * 1000 наших исходящих вызовов к Bot API и занимал бы PHP-FPM-воркеры на
     * время каждого curl'а — ровно та нагрузка, ради защиты от которой фильтр и
     * существует. Игроку одного предупреждения достаточно: он уже понял, почему
     * кнопка молчит.
     *
     * 🔴 Defensive: любая ошибка сети/API глотается — rate-limit обязан
     * ответить Telegram'у 200 в любом случае.
     *
     * @param array<array-key, mixed> $update
     */
    protected function notifyBlocked(array $update, bool $alreadyNotified): void
    {
        if ($alreadyNotified) {
            return;
        }

        $text = '⏳ Слишком много действий подряд. Подожди несколько секунд — и кнопки снова заработают.';

        $callbackId = $this->dig($update, 'callback_query', 'id');
        if (is_string($callbackId) && $callbackId !== '') {
            $this->callTelegram('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text'              => $text,
                'show_alert'        => false,
            ]);

            return;
        }

        $chatId = $this->dig($update, 'message', 'chat', 'id');
        if (is_int($chatId) || (is_string($chatId) && $chatId !== '')) {
            $this->callTelegram('sendMessage', [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }
    }

    /**
     * Короткий blocking-вызов Bot API. Таймауты жёсткие: путь редкий, но горячий.
     *
     * `protected` — это seam для тестов: подменяется в наследнике, чтобы unit
     * не ходил в сеть (урок [[feedback_taskhandler_telegram_init_in_tests]]).
     *
     * @param array<string, scalar> $params
     */
    protected function callTelegram(string $method, array $params): void
    {
        $token = getenv('telegram.API_KEY');
        if (!is_string($token) || $token === '' || !function_exists('curl_init')) {
            return;
        }

        try {
            $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            log_message('error', '[RateLimit] уведомление не ушло: ' . $e->getMessage());
        }
    }

    /**
     * Достаёт telegram user_id из любого типа update (message, callback_query,
     * edited_message и т.д.).
     *
     * @param array<array-key, mixed> $update
     */
    private function extractUserId(array $update): ?int
    {
        $candidates = [
            $this->dig($update, 'callback_query', 'from', 'id'),
            $this->dig($update, 'message', 'from', 'id'),
            $this->dig($update, 'edited_message', 'from', 'id'),
            $this->dig($update, 'inline_query', 'from', 'id'),
            $this->dig($update, 'chosen_inline_result', 'from', 'id'),
        ];
        foreach ($candidates as $id) {
            if (is_int($id)) {
                return $id;
            }
            if (is_string($id) && ctype_digit($id)) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Безопасно достаёт вложенное значение из распарсенного апдейта.
     *
     * Проверка `is_array` на каждом уровне — не формальность: тело приходит из
     * сети, любой уровень может оказаться строкой или null, а `$a['x']['y']`
     * на не-массиве даёт warning в рантайме и mixed-offset под phpstan L9.
     *
     * @param array<array-key, mixed> $update
     */
    private function dig(array $update, string ...$path): mixed
    {
        $node = $update;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
