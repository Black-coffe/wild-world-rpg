<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F1.5 — rate-limit для входящего Telegram-webhook'а.
 *
 * Защита от:
 *   - бот-ускорителей (один игрок шлёт callback'и сотнями в секунду)
 *   - DDoS Worker'а через спам callback-нажатий
 *   - read/write race condition нескольких быстрых нажатий одной кнопки
 *
 * Алгоритм: для каждого update'а извлекаем from.id (telegram user id) и
 * считаем сколько callback'ов пришло за окно 60 сек. Если > MAX_PER_MINUTE
 * — отвечаем 200 OK с пустым body (Telegram доволен и не шлёт повтор).
 *
 * Хранилище — стандартный CI4 cache. На текущей конфигурации это file-cache,
 * на проде с Redis (фаза F3) сразу заработает корректно из-за shared store.
 *
 * Тонкости:
 *   - Limit применяется ТОЛЬКО к /telegram/webhook, не к admin-страницам.
 *   - Если в payload нет from.id (channel post, edited_message без отправителя)
 *     — пропускаем без счётчика.
 *   - Возвращаем 200 OK (а не 429), чтобы Telegram не делал retry-bursts.
 */
class TelegramRateLimitFilter implements FilterInterface
{
    /** Максимум апдейтов в минуту на одного telegram-юзера. */
    private const MAX_PER_MINUTE = 30;

    /** Длина окна в секундах. */
    private const WINDOW_SECONDS = 60;

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
        $key = "tg_rate_{$userId}";

        $count = (int) ($cache->get($key) ?? 0);
        if ($count >= self::MAX_PER_MINUTE) {
            log_message('warning', "[RateLimit] Telegram user {$userId} превысил {$count}/мин — отброшен");
            // 200 OK с пустым body — Telegram не делает retry, просто получает ack
            return service('response')->setStatusCode(200)->setBody('');
        }

        // Атомарный инкремент. CI4 cache->increment создаёт ключ если его нет.
        $cache->increment($key);
        if ($count === 0) {
            // Только что создали ключ — задаём TTL.
            // Cache::increment не возвращает счётчик в file-driver предсказуемо,
            // поэтому делаем отдельный save с TTL только при первой записи.
            $cache->save($key, 1, self::WINDOW_SECONDS);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // не трогаем response
    }

    /**
     * Достаёт telegram user_id из любого типа update (message, callback_query,
     * edited_message и т.д.).
     */
    private function extractUserId(array $update): ?int
    {
        $candidates = [
            $update['callback_query']['from']['id'] ?? null,
            $update['message']['from']['id'] ?? null,
            $update['edited_message']['from']['id'] ?? null,
            $update['inline_query']['from']['id'] ?? null,
            $update['chosen_inline_result']['from']['id'] ?? null,
        ];
        foreach ($candidates as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return (int) $id;
            }
        }
        return null;
    }
}
