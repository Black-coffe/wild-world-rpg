<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;

/**
 * v0.51.89 (PvEService decomp Step 4) — extract Telegram notification
 * sender (telegram_user_id lookup → telegram_id resolve → length truncate
 * → Request::sendMessage HTML) у dedicated final class.
 *
 * Mirrors EventNotificationSender (NotificationPolicy decomp v0.51.71) як
 * "Telegram I/O єдина точка" pattern.
 */
final class PveNotificationSender
{
    /** Hard cap для Telegram message length (BotAPI limit ~4096 chars). */
    private const MAX_TEXT_LENGTH = 4000;

    public function __construct(
        private ?TelegramUserModel $telegramUserModel = null
    ) {
    }

    /**
     * @param array<string, mixed> $playerData characters row (потрібен `name`, `telegram_user_id`)
     */
    public function send(array $playerData, string $finalText): void
    {
        log_message('debug', "Пытаемся отправить сообщение в Telegram для {$playerData['name']}");

        if (empty($playerData['telegram_user_id'])) {
            log_message('error', "Ошибка: `telegram_user_id` отсутствует у игрока {$playerData['name']}");
            return;
        }

        $tgUserModel = $this->telegramUserModel ?? new TelegramUserModel();
        $tgUser = $tgUserModel->find($playerData['telegram_user_id']);

        if (!$tgUser) {
            log_message('error', "Ошибка: Telegram-пользователь не найден (ID={$playerData['telegram_user_id']})");
            return;
        }

        if (empty($tgUser['telegram_id'])) {
            log_message('error', "Ошибка: `telegram_id` отсутствует для пользователя {$tgUser['username']}");
            return;
        }

        log_message('debug', "Отправка сообщения в Telegram: chat_id={$tgUser['telegram_id']}");

        if (strlen($finalText) > self::MAX_TEXT_LENGTH) {
            log_message('warning', "Сообщение слишком длинное для Telegram! Обрезаем до " . self::MAX_TEXT_LENGTH . " символов.");
            $finalText = substr($finalText, 0, self::MAX_TEXT_LENGTH) . "...";
        }

        $result = Request::sendMessage([
            'chat_id'    => $tgUser['telegram_id'],
            'text'       => $finalText,
            'parse_mode' => 'HTML',
        ]);

        if (!$result->isOk()) {
            log_message('error', "Ошибка отправки сообщения в Telegram: " . $result->getDescription());
        } else {
            log_message('info', "Сообщение успешно отправлено в Telegram пользователю ID={$tgUser['telegram_id']}");
        }
    }
}
