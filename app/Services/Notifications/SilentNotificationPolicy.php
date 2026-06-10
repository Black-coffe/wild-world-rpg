<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsService;
use Throwable;

/**
 * W28 (ADR-083) — «тихий порог» уведомлений (silent threshold).
 *
 * Решает, доставлять ли РУТИННОЕ уведомление о завершении задачи тихо
 * (Telegram-флаг disable_notification=true: сообщение видно в чате, но телефон
 * не звенит/не вибрирует).
 *
 * Два гейта (оба должны разрешить тишину):
 *  1. **Killswitch** `notifications.silent_threshold.enabled` (GameSettings).
 *     При OFF (dormant на проде) → всегда «не тихо» → 0 изменений (как сегодня).
 *  2. **Per-char override** `characters.notify_sound`:
 *     0 (default) → следуем killswitch (тихо при ON);
 *     1 → игрок явно вернул себе звук → НЕ тихо, даже при ON.
 *
 * Тихими делаются ТОЛЬКО рутинные task-completions (см. routine-handler'ы,
 * переопределяющие {@see \App\TaskHandlers\BaseTaskHandler::isRoutineNotification()}).
 * Важные уведомления (PvP, события, ачивки, бродкасты, low-health, находки)
 * этот policy не трогают — они шлются обычным путём, всегда со звуком.
 *
 * Lookup (telegram_id → notify_sound) и значение killswitch кэшируются
 * per-process. Любая ошибка lookup/настроек → false (default = со звуком,
 * безопасный fallback).
 *
 * Зеркало {@see MediaSender::isMediaDisabled()} по структуре lookup'а.
 */
final class SilentNotificationPolicy
{
    /** @var array<int,bool> telegram_chat_id → notify_sound==1 (игрок хочет звук) */
    private static array $wantsSoundCache = [];

    private static ?bool $enabledCache = null;

    /** @var array{enabled:bool,start:int,end:int}|null кэш настроек тихих часов (E6 Ф4) */
    private static ?array $quietCache = null;

    /**
     * Должно ли рутинное уведомление для этого чата прийти тихо.
     *
     * Precedence (E6 Ф4 — ADR-108):
     *   1. Игрок явно вернул себе звук (notify_sound=1) → НИКОГДА не тихо (выбор игрока главнее).
     *   2. Тихие часы активны (ночь) → тихо (независимый killswitch quiet_hours).
     *   3. Тихий порог включён (silent_threshold) → тихо для дефолтного игрока.
     *   4. Иначе → со звуком.
     */
    public static function routineSilentFor(int $telegramChatId): bool
    {
        if ($telegramChatId <= 0) {
            return false;
        }
        // Игрок явно вернул себе звук → не тихо (даже ночью).
        if (self::charWantsSound($telegramChatId)) {
            return false;
        }
        // E6 Ф4 — тихие часы (ночь): рутинные уведомления беззвучны.
        if (self::inQuietHours()) {
            return true;
        }
        // Тихий порог (W28): при OFF (dormant) → со звуком.
        if (! self::enabled()) {
            return false;
        }

        return true;
    }

    /**
     * E6 Ф4 — активны ли «тихие часы» сейчас (killswitch quiet_hours.enabled +
     * текущий час в окне [start_hour, end_hour), с переходом через полночь).
     * `$hour` инжектируется в тестах; иначе текущий серверный час 0-23.
     */
    public static function inQuietHours(?int $hour = null): bool
    {
        $cfg = self::quietConfig();
        if (! $cfg['enabled']) {
            return false;
        }
        $h = $hour ?? (int) date('G');

        return self::isHourInWindow($h, $cfg['start'], $cfg['end']);
    }

    /**
     * Чистая проверка: попадает ли час в окно [start, end). start==end → пустое окно
     * (false). start<end → обычный интервал. start>end → окно через полночь.
     */
    public static function isHourInWindow(int $hour, int $start, int $end): bool
    {
        $hour  = ($hour % 24 + 24) % 24;
        $start = ($start % 24 + 24) % 24;
        $end   = ($end % 24 + 24) % 24;
        if ($start === $end) {
            return false;
        }
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }
        // через полночь (напр. 23..8)
        return $hour >= $start || $hour < $end;
    }

    /**
     * Настройки тихих часов (кэш per-process). Любая ошибка → выключено.
     *
     * @return array{enabled:bool,start:int,end:int}
     */
    private static function quietConfig(): array
    {
        if (self::$quietCache !== null) {
            return self::$quietCache;
        }
        try {
            $gs      = new GameSettingsService();
            $rawEn   = $gs->get('notifications.quiet_hours.enabled', false);
            $enabled = is_bool($rawEn) ? $rawEn : (is_numeric($rawEn) && (int) $rawEn === 1);
            $rawS    = $gs->get('notifications.quiet_hours.start_hour', 23);
            $rawE    = $gs->get('notifications.quiet_hours.end_hour', 8);
            $start   = is_numeric($rawS) ? (int) $rawS : 23;
            $end     = is_numeric($rawE) ? (int) $rawE : 8;
        } catch (Throwable) {
            return self::$quietCache = ['enabled' => false, 'start' => 23, 'end' => 8];
        }

        return self::$quietCache = ['enabled' => $enabled, 'start' => $start, 'end' => $end];
    }

    /**
     * Killswitch `notifications.silent_threshold.enabled`. Любая ошибка → false.
     */
    public static function enabled(): bool
    {
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }
        try {
            $v = (new GameSettingsService())->get('notifications.silent_threshold.enabled', false);
        } catch (Throwable) {
            return self::$enabledCache = false;
        }
        $enabled = is_bool($v) ? $v : (is_numeric($v) && (int) $v === 1);

        return self::$enabledCache = $enabled;
    }

    /**
     * Сброс кэша (тесты / админ-туллинг / смена настройки).
     */
    public static function reset(): void
    {
        self::$wantsSoundCache = [];
        self::$enabledCache    = null;
        self::$quietCache      = null;
    }

    /**
     * Per-char: игрок выставил notify_sound=1 (хочет звук). Кэшируется.
     * Нет персонажа / ошибка → false (следуем killswitch).
     */
    private static function charWantsSound(int $telegramChatId): bool
    {
        if (array_key_exists($telegramChatId, self::$wantsSoundCache)) {
            return self::$wantsSoundCache[$telegramChatId];
        }

        $tu   = (new TelegramUserModel())->where('telegram_id', $telegramChatId)->first();
        $tuId = self::intField($tu, 'id');
        if ($tuId <= 0) {
            return self::$wantsSoundCache[$telegramChatId] = false;
        }

        $char       = (new CharacterModel())->where('telegram_user_id', $tuId)->first();
        $wantsSound = self::intField($char, 'notify_sound');

        return self::$wantsSoundCache[$telegramChatId] = ($wantsSound === 1);
    }

    /**
     * Безопасно читает int-поле из строки модели (array или CI4 Entity/ArrayAccess).
     * Нет строки / поля / нечисловое значение → 0.
     */
    private static function intField(mixed $row, string $key): int
    {
        if (is_array($row) || $row instanceof \ArrayAccess) {
            $raw = $row[$key] ?? null;

            return is_numeric($raw) ? (int) $raw : 0;
        }

        return 0;
    }
}
