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
 * N-навигация (2026-06-11) — подменю «📊 Прогресс». Callback `progressHub`.
 *
 * Свёрнутые с карточки Перса read-only виды (достижения/титулы/рейтинг/экономика/что нового) —
 * анти button-soup (memory feedback_character_card_button_soup). Кнопки из единого источника
 * {@see ProfileHubService::progressButtons()}, та же killswitch-логика. Edit-in-place.
 */
final class ProgressHubAction extends BaseAction
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

        $level   = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 0;
        $buttons = ProfileHubService::progressButtons($level);
        $text    = "📊 *Прогресс*\n\n";
        $rows    = [];
        if ($buttons === []) {
            $text .= "_Разделы прогресса сейчас недоступны._";
        } else {
            $text .= "Достижения, титулы, рейтинг и твоя статистика — выбери раздел.";
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
