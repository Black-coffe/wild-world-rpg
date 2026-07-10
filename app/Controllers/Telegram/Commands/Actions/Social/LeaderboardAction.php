<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Social;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Social\LeaderboardScreen;
use App\Services\Social\LeaderboardService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Экран «🏆 Топ игроков». Callback `leaderboard` (живые) / `leaderboardLegends` (за всё время).
 *
 * Появился из firehose: двое игроков спрашивали бота про топ и получали «Не понял команду»
 * ({@see LeaderboardService} — там же обоснование двух вкладок). Рендер — общий
 * {@see LeaderboardScreen}, тот же, что зовёт текстовое слово «топ» в GenericmessageCommand.
 *
 * Edit-in-place (ADR-018): чистый text→text переход, вкладки переключаются НА МЕСТЕ.
 */
final class LeaderboardAction extends BaseAction
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

        $screen = new LeaderboardScreen();
        if (! (new LeaderboardService())->enabled()) {
            return Request::sendMessage(['chat_id' => $chatId] + $screen->disabledPayload());
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $tab = $this->callbackQuery->getData() === 'leaderboardLegends'
            ? LeaderboardScreen::TAB_LEGENDS
            : LeaderboardScreen::TAB_ACTIVE;

        return MediaSender::editTextOrSend($this->navTarget() + ['chat_id' => $chatId] + $screen->payload($characterId, $tab));
    }
}
