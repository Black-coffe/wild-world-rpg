<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\World\MoveSurfaceService;
use App\Services\World\TextMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
        // Координаты игрока нужны компасу биомов («🌋 Вулкан · редкий · северо-восток, далеко»);
        // если персонаж не резолвится — легенда рендерится в прежнем виде, без подсказок.
        [$px, $py] = $this->playerCoords($this->resolveCharacter());
        $legend    = (new TextMapService())->getLegend($px, $py);
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

    /**
     * (X, Y) игрока: позиция хранится как `cell_number`, координаты живут в `map`
     * (так же их достаёт компас движения в {@see MoveSurfaceService}).
     * Персонаж/клетка не нашлись → [null, null] и легенда рендерится без подсказок.
     *
     * @return array{0:int|null,1:int|null}
     */
    private function playerCoords(?CharacterEntity $character): array
    {
        if ($character === null) {
            return [null, null];
        }

        $cellRaw = $character['cell_number'] ?? null;
        if (! is_numeric($cellRaw)) {
            return [null, null];
        }

        $mapRow = (new MapModel())->where('cell_number', (int) $cellRaw)->first();
        if (! is_array($mapRow)) {
            return [null, null];
        }

        $xRaw = $mapRow['coordinate_x'] ?? null;
        $yRaw = $mapRow['coordinate_y'] ?? null;
        if (! is_numeric($xRaw) || ! is_numeric($yRaw)) {
            return [null, null];
        }

        return [(int) $xRaw, (int) $yRaw];
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
