<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairCraftedItemAction;
use App\Controllers\Telegram\Commands\Actions\Poll\PollVoteAction;
use App\Controllers\Telegram\Commands\BaseShiftingCommand;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * v0.51.78 (CallbackqueryCommand decomp Step 2) — extract inline prefix
 * routing blocks (pollVote_, upgrade_building_, confirm_upgrade_building_,
 * StartRelocationConfirm_) у dedicated dispatcher.
 *
 * Кожен prefix має різний dispatch pattern:
 *  - pollVote_{pollId}_{answerId}  → PollVoteAction::handleVote(pollId, answerId)
 *  - upgrade_building_{id}         → UpgradeBuildingAction::askForUpgrade()
 *  - confirm_upgrade_building_{id} → UpgradeBuildingAction::confirmUpgrade()
 *  - StartRelocationConfirm_{id}   → BaseShiftingCommand::handleCallback($cbq)
 *                                    (потребує Telegram instance)
 *
 * Public API:
 *   tryDispatch(callbackQuery): ?ServerResponse  — null якщо prefix не матчить.
 */
final class CallbackPrefixDispatcher
{
    private Telegram $telegram;

    public function __construct(Telegram $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Try to dispatch by prefix. Returns null якщо callback не починається
     * з відомого prefix — caller продовжує downstream routing.
     */
    public function tryDispatch(CallbackQuery $callbackQuery): ?ServerResponse
    {
        $callbackData = $callbackQuery->getData();

        if (str_starts_with($callbackData, 'pollVote_')) {
            return $this->dispatchPollVote($callbackQuery, $callbackData);
        }

        if (str_starts_with($callbackData, 'upgrade_building_')) {
            return $this->dispatchUpgradeBuildingAsk($callbackQuery);
        }

        if (str_starts_with($callbackData, 'confirm_upgrade_building_')) {
            return $this->dispatchUpgradeBuildingConfirm($callbackQuery);
        }

        if (str_starts_with($callbackData, 'StartRelocationConfirm_')) {
            return $this->dispatchStartRelocationConfirm($callbackQuery);
        }

        // S5b (v0.51.188+): repair flow (must check confirm_repair_ BEFORE repair_,
        // т.к. confirm_repair_ начинается с repair-prefix substring через подстроку).
        if (str_starts_with($callbackData, 'confirm_repair_')) {
            return $this->dispatchRepairConfirm($callbackQuery);
        }

        if (str_starts_with($callbackData, 'repair_')) {
            return $this->dispatchRepairAsk($callbackQuery);
        }

        return null;
    }

    /**
     * pollVote_{pollId}_{answerId} → PollVoteAction::handleVote(pollId, answerId).
     */
    private function dispatchPollVote(CallbackQuery $callbackQuery, string $callbackData): ServerResponse
    {
        $parts = explode('_', $callbackData);
        // [0] => pollVote, [1] => pollId, [2] => answerId
        if (count($parts) < 3) {
            return Request::emptyResponse();
        }

        $pollId   = (int) $parts[1];
        $answerId = (int) $parts[2];

        if (!class_exists(PollVoteAction::class)) {
            return Request::emptyResponse();
        }

        $handler = new PollVoteAction($callbackQuery);
        return $handler->handleVote($pollId, $answerId);
    }

    /**
     * upgrade_building_{id} — Шаг 1: показать требования + спросить подтверждение.
     */
    private function dispatchUpgradeBuildingAsk(CallbackQuery $callbackQuery): ServerResponse
    {
        if (!class_exists(UpgradeBuildingAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new UpgradeBuildingAction($callbackQuery);
        return $handler->askForUpgrade();
    }

    /**
     * confirm_upgrade_building_{id} — Шаг 2: списати і повисити уровень.
     */
    private function dispatchUpgradeBuildingConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        if (!class_exists(UpgradeBuildingAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new UpgradeBuildingAction($callbackQuery);
        return $handler->confirmUpgrade();
    }

    /**
     * S5b: repair_{log_id} → ask + show cost confirm prompt.
     */
    private function dispatchRepairAsk(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(RepairCraftedItemAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new RepairCraftedItemAction($callbackQuery);
        return $handler->askForRepair();
    }

    /**
     * S5b: confirm_repair_{log_id} → deduct + create task → completion handler.
     */
    private function dispatchRepairConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(RepairCraftedItemAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new RepairCraftedItemAction($callbackQuery);
        return $handler->confirmRepair();
    }

    /**
     * StartRelocationConfirm_{id} → answerCallbackQuery + BaseShiftingCommand::handleCallback.
     */
    private function dispatchStartRelocationConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
        ]);

        $cmd = new BaseShiftingCommand($this->telegram, null);
        return $cmd->handleCallback($callbackQuery);
    }
}
