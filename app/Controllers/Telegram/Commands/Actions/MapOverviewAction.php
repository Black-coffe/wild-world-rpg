<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\World\MapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-150 Слайс 1 — «🗺 Обзор»: фото карты мира с позицией игрока.
 *
 * Это демотированный бывший тупик-экран «Карта» (GD-фото без кнопок). Теперь он —
 * под-экран поверхности «Мир» (кнопка «🗺 Обзор» в компасе), и НЕСЁТ кнопку возврата
 * «🧭 Идти» (callback move) → не тупик. Фото рисует {@see MapService::showMapWithPlayer}
 * (media-off: MediaSender подставит caption как текст).
 */
class MapOverviewAction
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
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (! is_array($user)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.',
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (! $character instanceof CharacterEntity) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден в базе.',
            ]);
        }

        // «🔍 Что я открыл» — сосед по смыслу: этот экран показывает ВЕСЬ мир с точкой
        // игрока, а тот — только пройденное им самим (туман войны). Раньше личной карты
        // не существовало вовсе, и вопрос «где посмотреть открытое» упирался в тупик.
        $backButton = json_encode([
            'inline_keyboard' => [[
                ['text' => '🧭 Идти',        'callback_data' => 'move'],
                ['text' => '🔍 Что я открыл', 'callback_data' => 'exploredMap'],
            ]],
        ]);

        return (new MapService())->showMapWithPlayer($chatId, $character, $backButton !== false ? $backButton : null);
    }
}
