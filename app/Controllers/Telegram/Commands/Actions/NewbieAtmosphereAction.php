<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Notifications\MediaSender;
use App\Services\Onboarding\NewbieAtmosphereService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S9 (ROADMAP-RETENTION-10, ADR-147) — обработка выбора «шороха» ранней атмосферы.
 *
 * Exact-роут `atmRustle` (резолв по первому сегменту explode('_')[0]; хвост `_peek/_hide/_leave`
 * разбирает сам action — ключ БЕЗ хвостового `_`, урок мёртвых `npcAct_`). Рисует исход выбора
 * edit-in-place поверх сообщения-шороха ({@see NewbieAtmosphereService::resolveChoice}).
 *
 * 🔴 АНТИ-АБЬЮЗ / НЕСМЕРТЕЛЬНО: handler ЧИСТО навигационный — только перерисовывает текст. НЕ
 * трогает health/tired/ресурсы/золото/карту, не выдаёт наград (исход — атмосферный нарратив).
 * 🖼 MEDIA-OFF: editTextOrSend.
 */
final class NewbieAtmosphereAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! (new NewbieAtmosphereService())->enabled()) {
            // Defensive: killswitch OFF → кнопок нет, но если callback всё же пришёл — no-op.
            return Request::emptyResponse();
        }

        $data   = (string) $this->callbackQuery->getData();
        $choice = str_starts_with($data, 'atmRustle_') ? substr($data, strlen('atmRustle_')) : '';

        $text = NewbieAtmosphereService::resolveChoice($choice);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🗺 К карте', 'callback_data' => 'move']],
            ]]),
        ]);
    }
}
