<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RepairBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Caravan\CaravanBuyAction;
use App\Controllers\Telegram\Commands\Actions\Drone\CargoDroneSendAction;
use App\Controllers\Telegram\Commands\Actions\Drone\RecceDroneAction;
use App\Controllers\Telegram\Commands\Actions\Craft\Insurance\CraftInsureItemAction;
use App\Controllers\Telegram\Commands\Actions\Craft\Repair\NpcRepairAction;
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

        // ADR-041: ремонт оборонных зданий (camelCase-префиксы — НЕ коллизят с tool
        // `repair_`/`confirm_repair_`, т.к. 'repairBuilding_' не начинается с 'repair_').
        // confirm проверяем ПЕРЕД ask (конвенция).
        if (str_starts_with($callbackData, 'confirmRepairBuilding_')) {
            return $this->dispatchRepairBuildingConfirm($callbackQuery);
        }

        if (str_starts_with($callbackData, 'repairBuilding_')) {
            return $this->dispatchRepairBuildingAsk($callbackQuery);
        }

        // V25 (ADR-057): NPC-караван — покупка всего offer'а.
        if (str_starts_with($callbackData, 'caravanBuyAll_')) {
            return $this->dispatchCaravanBuyAll($callbackQuery);
        }

        // W2 (ADR-058): Drone-recon launch.
        if (str_starts_with($callbackData, 'recceDrone_')) {
            return $this->dispatchRecceDrone($callbackQuery);
        }

        // W3b (ADR-060): Cargo drone send (atomic delivery).
        if (str_starts_with($callbackData, 'cargoDroneSend_')) {
            return $this->dispatchCargoDroneSend($callbackQuery);
        }

        // V24 (ADR-056): NPC-страховой агент. confirm перед ask (конвенция).
        if (str_starts_with($callbackData, 'confirm_craft_insure_')) {
            return $this->dispatchCraftInsureConfirm($callbackQuery);
        }

        if (str_starts_with($callbackData, 'craftInsure_')) {
            return $this->dispatchCraftInsureAsk($callbackQuery);
        }

        // V23 (ADR-055): NPC-мастер на базе. confirm перед ask (конвенция).
        // ⚠️ Порядок: npc_repair_ ДО repair_ (S5), т.к. 'npc_repair_' начинается
        // с 'npc_' — не коллизит с 'repair_', но для читаемости держим вместе с S5.
        if (str_starts_with($callbackData, 'confirm_npc_repair_')) {
            return $this->dispatchNpcRepairConfirm($callbackQuery);
        }

        if (str_starts_with($callbackData, 'npc_repair_')) {
            return $this->dispatchNpcRepairAsk($callbackQuery);
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

    /** V25 (ADR-057): caravanBuyAll_{caravan_id} → списать gold + выдать ресурс. */
    private function dispatchCaravanBuyAll(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(CaravanBuyAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new CaravanBuyAction($callbackQuery);
        return $handler->handle();
    }

    /** W2 (ADR-058): recceDrone_{crafted_items_log_id} → revealAround + drain. */
    private function dispatchRecceDrone(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(RecceDroneAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new RecceDroneAction($callbackQuery);
        return $handler->handle();
    }

    /** W3b (ADR-060): cargoDroneSend_{log_id}_{res_id} → atomic delivery в base_storage. */
    private function dispatchCargoDroneSend(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(CargoDroneSendAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new CargoDroneSendAction($callbackQuery);
        return $handler->handle();
    }

    /** V24 (ADR-056): craftInsure_{log_id} → расчёт gold + Confirm. */
    private function dispatchCraftInsureAsk(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(CraftInsureItemAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new CraftInsureItemAction($callbackQuery);
        return $handler->askForInsurance();
    }

    /** V24 (ADR-056): confirm_craft_insure_{log_id} → списать gold + insured=1. */
    private function dispatchCraftInsureConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(CraftInsureItemAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new CraftInsureItemAction($callbackQuery);
        return $handler->confirmInsurance();
    }

    /** V23 (ADR-055): npc_repair_{log_id} → расчёт gold-цены + Confirm. */
    private function dispatchNpcRepairAsk(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(NpcRepairAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new NpcRepairAction($callbackQuery);
        return $handler->askForRepair();
    }

    /** V23 (ADR-055): confirm_npc_repair_{log_id} → списать gold + instant restore. */
    private function dispatchNpcRepairConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(NpcRepairAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new NpcRepairAction($callbackQuery);
        return $handler->confirmRepair();
    }

    /**
     * ADR-041: repairBuilding_{cb_id} → показать стоимость ремонта + confirm.
     */
    private function dispatchRepairBuildingAsk(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(RepairBuildingAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new RepairBuildingAction($callbackQuery);
        return $handler->askForRepair();
    }

    /**
     * ADR-041: confirmRepairBuilding_{cb_id} → списать ресурсы + восстановить hp.
     */
    private function dispatchRepairBuildingConfirm(CallbackQuery $callbackQuery): ServerResponse
    {
        if (! class_exists(RepairBuildingAction::class)) {
            return Request::emptyResponse();
        }
        $handler = new RepairBuildingAction($callbackQuery);
        return $handler->confirmRepair();
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
