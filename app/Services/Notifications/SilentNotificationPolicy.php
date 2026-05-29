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

    /**
     * Должно ли рутинное уведомление для этого чата прийти тихо.
     */
    public static function routineSilentFor(int $telegramChatId): bool
    {
        if ($telegramChatId <= 0) {
            return false;
        }
        // Killswitch OFF → всегда со звуком (dormant, 0 player-эффекта).
        if (! self::enabled()) {
            return false;
        }
        // Игрок явно вернул себе звук → не тихо.
        if (self::charWantsSound($telegramChatId)) {
            return false;
        }

        return true;
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
