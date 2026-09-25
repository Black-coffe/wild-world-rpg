<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * web-bridge-p1-01 (ADR-189 §3) — зарезервированный диапазон виртуальных Telegram-id.
 *
 * Персонаж без Telegram получает строку `telegram_users` с `telegram_id = idForAccount(account_id)`,
 * чтобы вся цепочка `from.id → telegram_users → characters` работала без правок хендлеров.
 *
 * Диапазон: `−(VIRTUAL_BASE + accounts.id)`, то есть |id| ∈ [2^52, 2^53). Bot API гарантирует, что
 * id пользователя и чата имеет «at most 52 significant bits» (https://core.telegram.org/bots/api#user,
 * #chat), значит |реальный id| < 2^52 и диапазон с ним не пересекается; |id| < 2^53 держит его
 * точным и в double (JS, JSON-декодеры).
 *
 * 🔴 Проверять ТОЛЬКО через {@see is()}, никогда по знаку: id групп/супергрупп тоже отрицательны.
 */
final class VirtualChat
{
    /** 2^52 — первое значение за пределом 52 значащих бит Telegram-id. */
    public const VIRTUAL_BASE = 4503599627370496;

    /** 2^53 — верхняя граница: дальше целые не точны в double. */
    private const VIRTUAL_LIMIT = 9007199254740992;

    public static function is(int $chatId): bool
    {
        return $chatId <= -self::VIRTUAL_BASE && $chatId > -self::VIRTUAL_LIMIT;
    }

    public static function idForAccount(int $accountId): int
    {
        if ($accountId <= 0 || $accountId >= self::VIRTUAL_LIMIT - self::VIRTUAL_BASE) {
            throw new \InvalidArgumentException("VirtualChat: account id {$accountId} out of range");
        }

        return -(self::VIRTUAL_BASE + $accountId);
    }
}
