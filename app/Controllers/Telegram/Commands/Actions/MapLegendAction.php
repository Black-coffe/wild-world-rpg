<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\World\MoveSurfaceService;
use App\Services\World\TextMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-150 Слайс 1 — тумблер легенды карты.
 *
 * Легенда (значки игрок/база/узел + 9 биомов) занимала ~80% текста поверхности «Мир»,
 * поэтому спрятана за кнопку «❓ Легенда». Экран не плодит сообщений — редактирует
 * ТО ЖЕ сообщение (edit-in-place):
 *  - callback `mapLegend` → текст сообщения меняется на полную легенду + кнопка «🧭 К карте»;
 *  - callback `mapBack`   → сообщение возвращается к компасу ({@see MoveSurfaceService::renderCompassInPlace}).
 *
 * Обе стороны — обычный текст (media-off-нейтрально; поверхность «Мир» всегда текстовая).
 */
class MapLegendAction
{
    private CallbackQuery $callbackQuery;
    private CharacterModel $characterModel;
    private TelegramUserModel $telegramUserModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery     = $callbackQuery;
        $this->characterModel    = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    public function handle(): ServerResponse
    {
        $message   = $this->callbackQuery->getMessage();
        $chatId    = $message->getChat()->getId();
        $messageId = $message->getMessageId();

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // «🧭 К карте» — возврат в компас без движения (edit-in-place).
        if ($this->callbackQuery->getData() === 'mapBack') {
            $character = $this->resolveCharacter();
            if ($character === null) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $this->callbackQuery->getId(),
                    'text'              => 'Персонаж не найден.',
                    'show_alert'        => true,
                ]);
            }

            return (new MoveSurfaceService())->renderCompassInPlace($chatId, $messageId, $character);
        }

        // «❓ Легенда» — показать полную легенду поверх карты (edit-in-place) + возврат.
        $legend   = (new TextMapService())->getLegend();
        $keyboard = json_encode([
            'inline_keyboard' => [[
                ['text' => '🧭 К карте', 'callback_data' => 'mapBack'],
            ]],
        ]);

        return Request::editMessageText([
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $legend,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $keyboard !== false ? $keyboard : null,
        ]);
    }

    private function resolveCharacter(): ?CharacterEntity
    {
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (! is_array($user)) {
            return null;
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();

        return $character instanceof CharacterEntity ? $character : null;
    }
}
