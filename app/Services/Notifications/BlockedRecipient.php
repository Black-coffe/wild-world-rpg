<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\TelegramUserModel;
use App\Services\Web\VirtualChat;
use Throwable;

/**
 * B16 (спека blocked-mark-1124): общий для крон-отправщиков ответ на «игрок заблокировал бота».
 *
 * `BaseTaskHandler` и `BaseObjectHandler` (safeSend*) на отказ Telegram зовут {@see handle()}.
 * Если отказ — блок, а получатель — настоящий личный чат, игрок получает метку
 * `telegram_users.blocked_at` (как в `BroadcastService`), пишется одна строка (WARNING; сбой записи
 * метки — ERROR с причиной) и отправщик останавливается: фото не повторяется текстом.
 *
 * Что считается блоком — только описание Telegram с `bot was blocked by the user` или
 * `user is deactivated` (без учёта регистра). Список `BroadcastDeliveryClassifier::isBlocked`
 * шире (голый «forbidden», «chat not found», …) и здесь сознательно не используется: «bot can't
 * initiate conversation» и «chat not found» — не блок, никого не помечают.
 *
 * Помечается только положительный chat id: группа (отрицательный id) и виртуальный чат `/play`
 * ({@see VirtualChat::is()}) не помечаются никогда. Уже помеченный игрок даты не меняет
 * (`TelegramUserModel::markBlocked` — условный UPDATE). Успешная отправка метку здесь не снимает.
 * Поставленная метка сразу попадает в набор {@see BlockedRecipientGate} этого процесса: следующие
 * фоновые отправки помеченному в Telegram не уходят (b16-blocked-recipients-1126).
 */
final class BlockedRecipient
{
    /** @var list<string> фрагменты описания Telegram, означающие «до игрока не достучаться» */
    private const BLOCK_PHRASES = [
        'bot was blocked by the user',
        'user is deactivated',
    ];

    /** Описание отказа Telegram — блок бота игроком (или удалённый аккаунт). */
    public static function isBlockDescription(string $description): bool
    {
        $haystack = strtolower($description);
        foreach (self::BLOCK_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Можно ли помечать этот chat id: только положительный целый id личного чата.
     *
     * @param int|string $chatId
     */
    public static function isMarkable($chatId): bool
    {
        if (is_string($chatId)) {
            if (preg_match('/^[1-9][0-9]*$/', $chatId) !== 1) {
                return false;
            }
            $chatId = (int) $chatId;
        }

        return $chatId > 0 && ! VirtualChat::is($chatId);
    }

    /**
     * Отказ Telegram при отправке крона: блок в личке → метка + одна строка лога, вернуть true
     * (отправщик дальше не логирует и не повторяет). Иначе false и ничего не делает — отправщик
     * ведёт себя как раньше. Никогда не бросает.
     *
     * @param int|string $chatId
     * @param string     $logPrefix `[Класс]` отправщика — строка лога атрибутируется ему
     * @param string     $method    метод Bot API (sendMessage / sendPhoto) для строки лога
     */
    public static function handle($chatId, string $description, string $logPrefix, string $method): bool
    {
        if (! self::isBlockDescription($description) || ! self::isMarkable($chatId)) {
            return false;
        }

        $line = $logPrefix . ' Telegram ' . $method . ': recipient ' . $chatId . ' blocked the bot';
        try {
            (new TelegramUserModel())->markBlocked((string) $chatId);
            BlockedRecipientGate::remember($chatId);
            log_message('warning', $line . ', marked: ' . $description);
        } catch (Throwable $e) {
            log_message('error', $line . ', mark failed: ' . $e->getMessage() . ' (' . $description . ')');
        }

        return true;
    }
}
