<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Notifications\MediaSender;
use App\Services\Quest\DailyTaskService;
use App\Services\Tasks\TasksSurfaceService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-150 Слайс 3 — экран-хаб «📋 Дела» (callback `tasksHub`).
 *
 * Навигационный экран: активные таймеры + полярная звезда + счётчики квестов/дейликов.
 * Рендер — общий {@see TasksSurfaceService} (тот же, что у slash `/tasks` и нижней кнопки
 * «📋 Дела») → без дрейфа трёх копий. Текст-поверхность, поэтому edit-in-place через
 * `MediaSender::editTextOrSend` (ADR-018: навигация редактирует, не спамит новыми).
 */
final class TasksHubAction extends BaseAction
{
    private TasksSurfaceService $surface;
    private DailyTaskService $daily;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->surface = new TasksSurfaceService();
        $this->daily   = new DailyTaskService();
    }

    public function handle(): ServerResponse
    {
        return $this->executeWithCharacter(function ($user, $character): ServerResponse {
            // Killswitch OFF → кнопки нигде нет, но защищаемся от прямого callback
            // (устаревшее сообщение в истории / ручной вызов).
            if (! $this->surface->enabled()) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $this->callbackQuery->getId(),
                    'text'              => 'Экран «Дела» сейчас недоступен. Активные задачи — команда /tasks',
                    'show_alert'        => true,
                ]);
            }

            // Ленивое назначение набора дейликов за сегодня (идемпотентно) — чтобы счётчик
            // «Задания дня 0/3» на хабе не врал, если фоновый хук ещё не отработал.
            if ($this->daily->enabled()) {
                $this->daily->ensureAssigned($character);
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
