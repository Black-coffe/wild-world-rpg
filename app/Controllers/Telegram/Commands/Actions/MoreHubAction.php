<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\More\MoreSurfaceService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-150 Слайс 4 — экран-хаб «⚙️ Ещё» (callback `moreHub`).
 *
 * Дом группы: торговля, Арена, развлечения (+Оракул внутри), фракция/проект, реферал,
 * подать, помощь, настройки. Рендер общий — {@see MoreSurfaceService} (тот же экран, что у
 * нижней кнопки «⚙️ Ещё») → без дрейфа копий. Текст-поверхность → edit-in-place (ADR-018).
 */
final class MoreHubAction extends BaseAction
{
    private MoreSurfaceService $surface;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->surface = new MoreSurfaceService();
    }

    public function handle(): ServerResponse
    {
        return $this->executeWithCharacter(function ($user, $character): ServerResponse {
            // Killswitch OFF → кнопки нигде нет, но защищаемся от прямого callback
            // (устаревшее сообщение в истории / ручной вызов).
            if (! $this->surface->enabled()) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $this->callbackQuery->getId(),
                    'text'              => 'Экран «Ещё» сейчас недоступен.',
                    'show_alert'        => true,
                ]);
            }

            $screen = $this->surface->buildScreen($character);

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'                     => $screen['text'],
                'parse_mode'               => 'Markdown',
                'disable_web_page_preview' => true,
                'reply_markup'             => json_encode($screen['keyboard']),
            ]);
        });
    }
}
