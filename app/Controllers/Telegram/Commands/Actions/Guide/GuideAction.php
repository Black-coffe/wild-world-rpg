<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Guide;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Onboarding\GuideService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-127 — навигация по справочнику «📖 Путь новичка» (edit-in-place).
 *
 * Один exact-роут `guide` (см. Config\CallbackRoutes) ловит ВСЕ callback'и хаба:
 *   • `guide`           → оглавление;
 *   • `guide_<key>`     → раздел (например `guide_combat`).
 * Резолвер берёт первый сегмент `explode('_', data)[0]` = `guide`, а ключ раздела
 * разбирает сам action (см. {@see GuideService::keyFromCallback()}). Ключ-роут БЕЗ
 * хвостового `_` — урок мёртвых `npcAct_` (ADR-089): иначе матч никогда не сработал бы.
 *
 * 🔴 АНТИ-АБЬЮЗ: handler ЧИСТО навигационный — только перерисовывает текст через
 * {@see GuideService}. Не трогает персонажа, не выдаёт ресурсы/золото, не телепортирует,
 * не раскрывает карту. getUserAndCharacter здесь не нужен — справочник одинаков для всех.
 *
 * 🖼 MEDIA-OFF: editTextOrSend (без фото) корректен при disable_media.
 */
final class GuideAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $callbackData = (string) $this->callbackQuery->getData();
        $key          = GuideService::keyFromCallback($callbackData);

        $service = new GuideService();
        $payload = $key === '' ? $service->indexPayload() : $service->sectionPayload($key);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $payload['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $payload['reply_markup'],
        ]);
    }
}
