<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\TelegramUserModel;
use App\Services\Notifications\BroadcastDeliveryClassifier;
use Longman\TelegramBot\Request;

/**
 * v0.51.89 (PvEService decomp Step 4) — extract Telegram notification
 * sender (telegram_user_id lookup → telegram_id resolve → length truncate
 * → Request::sendMessage HTML) у dedicated final class.
 *
 * Mirrors EventNotificationSender (NotificationPolicy decomp v0.51.71) як
 * "Telegram I/O єдина точка" pattern.
 *
 * Гигиена blocked_at (2026-06-10): заблокировавшим бота НЕ шлём (авто-PvE крон
 * слал уведомление за каждый бой → 240 ERROR/день «bot was blocked» от одного
 * застрявшего чара); 403 при отправке → markBlocked, успех → clearBlocked
 * (self-heal, как BroadcastService).
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
     * @param array<string, mixed>|\App\Entities\CharacterEntity $playerData row/Entity з `name` + `telegram_user_id`
     */
    public function send(array|\App\Entities\CharacterEntity $playerData, string $finalText): void
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

        $tgIdRaw = $tgUser['telegram_id'];
        $tgId    = is_scalar($tgIdRaw) ? (string) $tgIdRaw : '';

        if (self::isRecipientBlocked($tgUser['blocked_at'] ?? null)) {
            log_message('debug', "PvE notify skip: пользователь {$tgId} заблокировал бота (blocked_at).");
            return;
        }

        log_message('debug', "Отправка сообщения в Telegram: chat_id={$tgId}");

        if (strlen($finalText) > self::MAX_TEXT_LENGTH) {
            log_message('warning', "Сообщение слишком длинное для Telegram! Обрезаем до " . self::MAX_TEXT_LENGTH . " символов.");
            $finalText = substr($finalText, 0, self::MAX_TEXT_LENGTH) . "...";
        }

        $result = Request::sendMessage([
            'chat_id'    => $tgId,
            'text'       => $finalText,
            'parse_mode' => 'HTML',
        ]);

        if (!$result->isOk()) {
            $desc = $result->getDescription();
            if (BroadcastDeliveryClassifier::isBlocked($desc)) {
                // Недостижим навсегда (403 blocked / deactivated) — отмечаем и больше
                // не шлём (гейт выше); warning, не error: ситуация штатно обработана.
                $tgUserModel->markBlocked($tgId);
                log_message('warning', "PvE notify: получатель {$tgId} недостижим, отмечен blocked_at: {$desc}");
            } else {
                log_message('error', "Ошибка отправки сообщения в Telegram: " . $desc);
            }
        } else {
            // Self-heal: пользователь вернулся — снимаем отметку (условный UPDATE, no-op если не отмечен).
            $tgUserModel->clearBlocked($tgId);
            log_message('info', "Сообщение успешно отправлено в Telegram пользователю ID={$tgId}");
        }
    }

    /**
     * Получатель отмечен как заблокировавший бота? Pure — тестируется юнитом.
     *
     * @param mixed $blockedAt значение telegram_users.blocked_at (timestamp|null)
     */
    public static function isRecipientBlocked(mixed $blockedAt): bool
    {
        return ! empty($blockedAt);
    }
}
