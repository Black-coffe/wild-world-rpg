<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Collections;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\CollectionService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * E19 (ADR-119) — сдача предмета в слот коллекции. Callback `collDonate_<slotId>` (prefix `collDonate`).
 *
 * CollectionService::donate списывает required_qty ресурса (атомарно) и заполняет слот навсегда;
 * при завершении коллекции — выдаёт титул-награду. Toast-результат + перерисовка экрана коллекции
 * (реюз {@see CollectionOpenAction::renderFor}).
 */
final class CollectionDonateAction extends BaseAction
{
    private CollectionService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new CollectionService();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $this->callbackQuery->getId(),
                'text'              => 'Персонаж не найден.',
                'show_alert'        => true,
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $data   = (string) $this->callbackQuery->getData();
        $parts  = explode('_', $data);
        $slotId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

        $result = $this->service->donate($charId, $slotId);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $this->toast($result),
            'show_alert'        => $result['reason'] === 'insufficient',
        ]);

        // Перерисовать коллекцию (если знаем её id) или вернуть в обзор музея.
        $slot         = is_array($result['slot'] ?? null) ? $result['slot'] : [];
        $collectionId = is_numeric($slot['collection_id'] ?? null) ? (int) $slot['collection_id'] : 0;
        if ($collectionId > 0) {
            return (new CollectionOpenAction($this->callbackQuery))->renderFor($collectionId);
        }
        return (new CollectionsAction($this->callbackQuery))->handle();
    }

    /**
     * @param array{ok:bool, reason:string, slot?:array<int|string,mixed>, collectionCompleted?:bool, titleAwarded?:?string} $result
     */
    private function toast(array $result): string
    {
        if ($result['ok'] === true) {
            if (($result['collectionCompleted'] ?? false) === true) {
                $title = $result['titleAwarded'] ?? null;
                return is_string($title) && $title !== ''
                    ? "🎉 Коллекция собрана! Титул «{$title}» твой!"
                    : '🎉 Коллекция собрана!';
            }
            return '🏛 Сдано в музей!';
        }

        return match ($result['reason']) {
            'insufficient' => 'Не хватает предметов, чтобы закрыть этот слот.',
            'already'      => 'Этот экспонат уже в музее.',
            'level'        => 'Музей тебе ещё не открыт.',
            default        => 'Сейчас недоступно.',
        };
    }
}
