<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Классификация результата broadcast-отправки по Telegram API-описанию ошибки.
 *
 * Контекст (находка 2026-05-31): `Request::sendMessage` НЕ бросает exception на
 * API-ошибки (403/429) — возвращает ServerResponse с `isOk()=false`. Прежний
 * MessageController не проверял isOk → счётчик «sent» завышен (считал отправку
 * заблокировавшему за успех). Этот классификатор отделяет:
 *  - blocked (заблокировал бота / удалён / чат не найден) → недостижим, НЕ ретраить;
 *  - rate-limited (429) → достижим, ретраить с задержкой;
 *  - прочее → транзиент/неизвестно.
 *
 * Pure (без I/O) — тестируется юнитом.
 */
final class BroadcastDeliveryClassifier
{
    /** Подстроки Telegram-описания, означающие «получатель недостижим навсегда». */
    private const BLOCKED_NEEDLES = [
        'bot was blocked',
        'user is deactivated',
        'chat not found',
        'user is deactivated',
        'bot can\'t initiate conversation',
        'peer_id_invalid',
        'forbidden',
    ];

    /**
     * Недостижим (заблокировал/удалён/не найден) → mark blocked, не ретраить.
     */
    public static function isBlocked(string $description): bool
    {
        $d = mb_strtolower(trim($description));
        if ($d === '') {
            return false;
        }
        foreach (self::BLOCKED_NEEDLES as $needle) {
            if (mb_strpos($d, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 429 rate-limit (Too Many Requests / retry after) → достижим, ретраить позже.
     */
    public static function isRateLimited(string $description): bool
    {
        $d = mb_strtolower(trim($description));
        return mb_strpos($d, 'too many requests') !== false
            || mb_strpos($d, 'retry after') !== false;
    }

    /**
     * Итоговая категория: 'blocked' | 'rate_limited' | 'other'.
     */
    public static function classify(string $description): string
    {
        if (self::isBlocked($description)) {
            return 'blocked';
        }
        if (self::isRateLimited($description)) {
            return 'rate_limited';
        }
        return 'other';
    }
}
