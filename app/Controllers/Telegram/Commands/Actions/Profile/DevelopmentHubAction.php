<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Player\ProfileHubService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * N-навигация (2026-06-11) — подменю «⚙️ Развитие». Callback `developmentHub`.
 *
 * Свёрнутые с карточки Перса прогресс-фичи (специализация/проект фракции/дрон/модернизация) —
 * анти button-soup (memory feedback_character_card_button_soup). Lock-состояния сохранены
 * (UX-DISCOVERABILITY). Кнопки из {@see ProfileHubService::developmentButtons()}. Edit-in-place.
 */
final class DevelopmentHubAction extends BaseAction
{
    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level   = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $buttons = ProfileHubService::developmentButtons($charId, $level);

        $text = "⚙️ *Развитие*\n\n";
        $rows = [];
        if ($buttons === []) {
            $text .= "_Разделы развития сейчас недоступны._";
        } else {
            $text .= "Специализация, проект фракции, боевой дрон и модернизация снаряжения.";
            for ($i = 0; $i < count($buttons); $i += 2) {
                $rows[] = array_slice($buttons, $i, 2);
            }
        }
        $rows[] = [['text' => '◀️ Я', 'callback_data' => 'character']];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }
}
