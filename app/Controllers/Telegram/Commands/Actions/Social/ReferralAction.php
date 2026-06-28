<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Social;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\ReferralService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — экран «👥 Позови выжившего» (edit-in-place с карточки Перса).
 *
 * Exact-роут `referral` (см. Config\CallbackRoutes). Показывает личную пригласительную ссылку
 * ({@see ReferralService::inviteLink}) + честный счётчик откликов. Кнопка входа добавляется в
 * CharacterService только при killswitch `referral.enabled`.
 *
 * 🔴 АНТИ-АБЬЮЗ: handler ЧИСТО навигационный — рисует ссылку+счётчик. Не выдаёт наград, не трогает
 *    персонажа/ресурсы/золото (титул выдаётся отдельно cron'ом за реальный прогресс приглашённого).
 * 🖼 MEDIA-OFF: editTextOrSend, весь смысл в тексте.
 */
final class ReferralAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $service = new ReferralService();
        if (! $service->enabled()) {
            // Defensive: killswitch OFF → кнопки нет, но если callback всё же пришёл — no-op.
            return Request::emptyResponse();
        }

        [$user, $character] = $this->getUserAndCharacter();
        if (! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id'])) {
            return Request::emptyResponse();
        }

        $payload = $service->screenPayload((int) $user['id']);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'                     => $payload['text'],
            'parse_mode'               => 'Markdown',
            'reply_markup'             => $payload['reply_markup'],
            'disable_web_page_preview' => true,
        ]);
    }
}
