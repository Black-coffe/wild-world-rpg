<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Titles;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\TitleService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E11 (ADR-112) — экипировка титула. Callback `titleSet_<id>` (prefix `titleSet`).
 *
 * Валидирует владение (TitleService::setActive отклоняет чужой/неоткрытый), затем перерисовывает
 * экран «🎖 Титулы» (реюз TitlesAction). Гейт killswitch titles.enabled.
 */
final class TitleSetAction extends BaseAction
{
    private TitleService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new TitleService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character || ! $this->service->enabled()) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Недоступно.',
                'show_alert'        => true,
            ]);
        }

        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $data    = (string) $this->callbackQuery->getData();
        $parts   = explode('_', $data);
        $titleId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

        $ok = $titleId > 0 && $this->service->setActive($charId, $titleId);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $ok ? '🎖 Титул надет!' : 'Этот титул тебе ещё не открыт.',
        ]);

        // Перерисовать экран титулов (актуальный активный + кнопки).
        return (new TitlesAction($this->callbackQuery))->handle();
    }
}
