<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * Единый честный подъём Telegram-моста для кода вне webhook'а (крон, воркер, сервисы).
 *
 * Раньше пять мест держали свою копию lazy-init, и в аварийной ветке каждая делала
 * `new Telegram('invalid', 'invalid')` — а этот конструктор бросает то же исключение,
 * которое ветка ловила, так что «пустышка» роняла вызывающий код вместо того, чтобы
 * его спасти. Здесь неудача — это `false` и одна `error`-строка, без исключения.
 *
 * Мост один на процесс (статика `Longman\TelegramBot\Request` и так глобальна),
 * поэтому повторный вызов после успеха — no-op.
 */
final class TelegramBridge
{
    private static ?Telegram $telegram = null;

    /**
     * Поднимает мост из `telegram.API_KEY` / `telegram.BOT_USERNAME`.
     *
     * @return bool true — мост готов; false — не удалось (причина в логе). Никогда не бросает.
     */
    public static function ensure(): bool
    {
        if (self::$telegram !== null) {
            return true;
        }

        try {
            $telegram = new Telegram(
                (string) getenv('telegram.API_KEY'),
                (string) getenv('telegram.BOT_USERNAME')
            );
            Request::initialize($telegram);
            self::$telegram = $telegram;

            return true;
        } catch (Throwable $e) {
            log_message('error', '[TelegramBridge] Telegram init failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Поднятый мост или null, если `ensure()` ещё не удавался. */
    public static function instance(): ?Telegram
    {
        return self::$telegram;
    }

    /** Только для тестов: забыть поднятый мост, чтобы следующий `ensure()` строил заново. */
    public static function reset(): void
    {
        self::$telegram = null;
    }
}
