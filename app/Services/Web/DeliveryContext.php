<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * web-bridge-p1-01 (ADR-189 §5) — чей интерактивный запрос сейчас обрабатывается.
 *
 * `UpdatePipeline` ставит чат действующего игрока на время апдейта и сбрасывает в `finally`;
 * слой доставки читает его, чтобы отличить ответ действующему от фоновой отправки. Null —
 * интерактивного актёра нет (Worker, cron, spark: всё фоновое).
 */
final class DeliveryContext
{
    private static ?int $actor = null;

    public static function setActor(?int $chatId): void
    {
        self::$actor = $chatId;
    }

    public static function actor(): ?int
    {
        return self::$actor;
    }

    public static function reset(): void
    {
        self::$actor = null;
    }
}
