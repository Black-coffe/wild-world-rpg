<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * V20 (ADR-051) — экран фракц-проекта: прогресс, статус баффа, кнопка вклада.
 * callback `factionProject`.
 */
class FactionProjectAction extends FactionProjectBaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->reply($chatId, 'Пользователь не найден или персонаж не определён.');
        }

        if (! $this->projectService->enabled()) {
            return $this->reply($chatId, 'Проект фракции сейчас недоступен.');
        }

        $factionId = $this->projectService->factionIdFor((int) $character['id']);
        if ($factionId === 0) {
            return $this->reply($chatId, 'Сначала вступи во фракцию (с 10 уровня) — тогда откроется общий проект.');
        }

        $screen = $this->buildProjectScreen($factionId);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editTextOrSend([
            'chat_id'      => $chatId,
            'message_id'   => $this->callbackQuery->getMessage()->getMessageId(),
            'text'         => $screen['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($screen['keyboard']),
        ]);
    }

    private function reply(int $chatId, string $text): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $text]);
    }
}
