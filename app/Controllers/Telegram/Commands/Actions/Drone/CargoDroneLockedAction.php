<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * CLAUDE.md §🎮 UX-DISCOVERABILITY (правило #4, 2026-05-27) + W3b (ADR-060) —
 * lock-state кнопки «🔒 Карго-дрон». Зеркало FactionProjectLockedAction.
 *
 * Кнопка «🚚 Карго-дрон» в move-keyboard видна ВСЕГДА (когда cargo enabled).
 * Если у игрока нет инстанса DroneCargo с qty>0 или RoboticsWorkshop < L2 —
 * вместо активной кнопки показывается lock-state, клик по которому приводит
 * сюда. Alert объясняет prerequisite, чтобы игрок мог дойти до фичи без
 * предзнаний.
 */
final class CargoDroneLockedAction extends BaseAction
{
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

        $characterId = (int) ($character['id'] ?? 0);

        $itemModel = new CraftedItemsModel();
        $cargoRow  = $itemModel->where('name_eng', 'DroneCargo')->first();
        $cargoId   = is_array($cargoRow) && is_numeric($cargoRow['id'] ?? null) ? (int) $cargoRow['id'] : 0;

        $ownsCargo = false;
        if ($cargoId > 0) {
            $log = (new CraftedItemsLogModel())
                ->where('character_id', $characterId)
                ->where('crafted_item_id', $cargoId)
                ->where('quantity >', 0)
                ->first();
            $ownsCargo = is_array($log);
        }

        $wsLevel = $this->workshopLevel($characterId);

        if ($ownsCargo) {
            $msg = "🚚 Карго-дрон уже скрафчен — попробуй ещё раз. Если кнопка снова в lock-state, обновлю экран Действия.";
        } elseif ($wsLevel < 2) {
            $msg = "🔒 Карго-дрон собирается в Мастерской робототехники (нужен уровень 2 — сейчас у тебя {$wsLevel}).\n\nПрокачай Мастерскую через " . \App\Services\Telegram\BotMenuService::menuLabel('base') . " → 🤖 Роботы.";
        } else {
            $msg = "🔒 Карго-дрон ещё не скрафчен.\n\nЗайди в " . \App\Services\Telegram\BotMenuService::menuLabel('craft')
                . " → 🔧 Стандартный крафт → 🚚 Карго-дрон — у тебя уже подходит уровень Мастерской.";
        }

        return Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
            'cache_time'        => 0,
        ]);
    }

    private function workshopLevel(int $characterId): int
    {
        $building   = (new BuildingModel())->where('name_en', 'RoboticsWorkshop')->first();
        $buildingId = is_array($building) && is_numeric($building['id'] ?? null) ? (int) $building['id'] : 0;
        if ($buildingId === 0) {
            return 0;
        }
        $owned = (new CharacterBuildingModel())
            ->where('character_id', $characterId)
            ->where('building_id', $buildingId)
            ->first();
        return is_array($owned) && is_numeric($owned['level'] ?? null) ? (int) $owned['level'] : 0;
    }
}
