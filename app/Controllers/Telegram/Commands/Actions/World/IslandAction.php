<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\World;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\World\IslandPulseService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S7 (ROADMAP-RETENTION-10, ADR-145) — экран «🌍 Остров живёт» (edit-in-place с карты).
 *
 * Exact-роут `island` (см. Config\CallbackRoutes). Рисует ПРАВДИВЫЙ пульс острова
 * ({@see IslandPulseService}) — реальные агрегаты действий живых выживших. Кнопка входа
 * добавляется в MoveCharacterAction только при killswitch `social.presence.enabled`.
 *
 * 🔴 АНТИ-АБЬЮЗ: handler ЧИСТО навигационный — только перерисовывает текст. Не трогает
 * персонажа, не выдаёт ресурсы/золото, не телепортирует, не раскрывает карту.
 * getUserAndCharacter не нужен — пульс одинаков для всех.
 *
 * 🔴 ЧЕСТНОСТЬ: только true-data (IslandPulseService инвариант). 🖼 MEDIA-OFF: editTextOrSend.
 */
final class IslandAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $service = new IslandPulseService();
        if (! $service->enabled()) {
            // Defensive: killswitch OFF → кнопки нет, но если callback всё же пришёл — no-op.
            return Request::emptyResponse();
        }

        $payload = $service->payload();

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $payload['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $payload['reply_markup'],
        ]);
    }
}
