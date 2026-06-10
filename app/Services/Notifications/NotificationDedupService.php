<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\GameSettings\GameSettingsService;
use Throwable;

/**
 * E6 (ADR-108) Фаза 4 — дедупликация рутинных уведомлений.
 *
 * Подавляет ПОВТОРНУЮ доставку идентичного рутинного уведомления тому же чату в
 * пределах короткого окна (anti-flood для одинаковых «крафт готов» / «добыча
 * завершена» подряд). Работает ТОЛЬКО для routine-handler'ов (см.
 * {@see \App\TaskHandlers\BaseTaskHandler::isRoutineNotification()}); важные
 * уведомления не дедуплицируются.
 *
 * Гейт: killswitch `notifications.dedup.enabled` (default false → dormant → никогда
 * не подавляет). Окно — `notifications.dedup.window_seconds` (default 60).
 *
 * Хранилище — CI4 cache (file/redis): ключ = chat+hash(тело), TTL = окно. Любая
 * ошибка cache/настроек → false (не подавляем, безопасный fallback = доставить).
 */
final class NotificationDedupService
{
    private static ?bool $enabledCache = null;
    private static ?int $windowCache = null;

    /**
     * Подавить ли это рутинное уведомление как дубль. При первом вызове для
     * (chat, тело) — запоминает и возвращает false (доставить); повторный в окне —
     * true (подавить).
     */
    public static function shouldSuppress(int $chatId, string $body): bool
    {
        if ($chatId === 0 || ! self::enabled()) {
            return false;
        }
        try {
            $cache = cache();
            $key   = self::cacheKey($chatId, $body);
            if ($cache->get($key) !== null) {
                return true; // уже доставляли это в окне → подавить дубль
            }
            $cache->save($key, 1, max(1, self::windowSeconds()));
        } catch (Throwable $e) {
            log_message('error', '[NotificationDedupService] ' . $e->getMessage());
            return false;
        }

        return false;
    }

    /** Чистый ключ кэша: только [a-z0-9_], тело хэшируется. */
    public static function cacheKey(int $chatId, string $body): string
    {
        return 'notifdedup_' . $chatId . '_' . md5($body);
    }

    public static function enabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }
        try {
            $v = (new GameSettingsService())->get('notifications.dedup.enabled', false);
        } catch (Throwable) {
            return self::$enabledCache = false;
        }

        return self::$enabledCache = is_bool($v) ? $v : (is_numeric($v) && (int) $v === 1);
    }

    public static function windowSeconds(): int
    {
        if (self::$windowCache !== null) {
            return self::$windowCache;
        }
        try {
            $v = (new GameSettingsService())->get('notifications.dedup.window_seconds', 60);
        } catch (Throwable) {
            return self::$windowCache = 60;
        }

        return self::$windowCache = is_numeric($v) ? max(1, (int) $v) : 60;
    }

    /** Сброс per-process кэша (тесты / смена настройки). */
    public static function reset(): void
    {
        self::$enabledCache = null;
        self::$windowCache  = null;
    }
}
