<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\TeleportCostService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// <-- Импортируем сервис, отвечающий за проверку золота

class TeleportAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => '🤖 Это снова я – *Роби*!\n\nПользователь не найден или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        // Модели для проверки предметов
        $craftedItemModel    = new CraftedItemsModel();
        $craftedItemLogModel = new CraftedItemsLogModel();
        $characterModel      = new CharacterModel();

        // 1. Проверяем "Портативный телепорт"
        $portableTeleportRow = $craftedItemModel->where('name_eng', 'PortableTeleport')->first();
        $hasPortableTeleport = false;
        if ($portableTeleportRow) {
            $countPortable = $craftedItemLogModel
                ->where('crafted_item_id', $portableTeleportRow['id'])
                ->where('character_id', $character['id'])
                ->selectSum('quantity', 'total_qty')
                ->first();
            if ($countPortable && ($countPortable['total_qty'] ?? 0) >= 1) {
                $hasPortableTeleport = true;
            }
        }

        // 2. Проверяем "Рюкзак телепорт" (TeleportBackpack)
        $teleportBackpackRow = $craftedItemModel->where('name_eng', 'TeleportBackpack')->first();
        $hasTeleportBackpack = false;
        $backpackUsesLeft = 0; // сколько раз можно использовать
        if ($teleportBackpackRow) {
            // 2) Ищем запись в crafted_items_log
            $backpackLogRow = $craftedItemLogModel
                ->where('crafted_item_id', $teleportBackpackRow['id'])
                ->where('character_id', $character['id'])
                ->first();

            if ($backpackLogRow) {
                // 3) Смотрим поле durability_count
                $usesLeft = $backpackLogRow['durability_count'] ?? 0;
                // при желании можно проверить ещё и quantity, если у игрока может быть несколько экземпляров
                $qty = $backpackLogRow['quantity'] ?? 0;

                if ($qty > 0 && $usesLeft > 0) {
                    $hasTeleportBackpack = true;
                    $backpackUsesLeft = $usesLeft;
                }
            }
        }

        // 3. Проверяем опыт / золото, используя CharacterModel и TeleportCostService
        $characterData       = $characterModel->find($character['id']);
        $hasEnoughExperience = ($characterData['experience'] ?? 0) > 1.01;

        $teleportCostService = new TeleportCostService();
        $canPayGold = $teleportCostService->canPayTeleport($characterData);

        // Формируем текст и кнопки
        $text = "🤖 Это снова я – *Роби*!\n\n";
        $availableButtons = [];

        // --- Рюкзак телепорт
        if ($hasTeleportBackpack) {
            $text .= "*Рюкзак телепорт*\n"
                . "_Позволяет вернуться на базу без затрат_\n"
                . "_(осталось {$backpackUsesLeft} использований)_\n\n";

            $availableButtons[] = [
                'text' => '🎒 Рюкзак телепорт',
                'callback_data' => 'TeleportUse_Backpack'
            ];
        }

        // --- Портативный телепорт
        if ($hasPortableTeleport) {
            $text .= "*Портативный телепорт*\n_Отнимет 1 заряд устройства_\n\n";
            $availableButtons[] = [
                'text' => '📡 Портативный телепорт',
                'callback_data' => 'TeleportUse_Portable'
            ];
        }

        // --- Телепорт за опыт
        if ($hasEnoughExperience) {
            $text .= "*Телепорт за опыт*\n_Отнимет 1 единицу опыта_\n\n";
            $availableButtons[] = [
                'text' => '🔮 За опыт',
                'callback_data' => 'TeleportUse_WithExperience'
            ];
        }

        // --- Телепорт за золото
        if ($canPayGold) {
            $cost = $teleportCostService->calculateTeleportCost($character['level']);
            $formattedCost = number_format($cost, 0, '.', ' ');
            $text .= "*Телепорт за золото*\n_Снимет {$formattedCost} золота_\n\n";
            $availableButtons[] = [
                'text' => '🪙 За золото',
                'callback_data' => 'TeleportUse_WithGold'
            ];
        }

        if (empty($availableButtons)) {
            // Нет ни одного способа телепорта
            $text .= "Увы, у тебя нет доступа ни к одному способу телепортации.";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🚜 Переехать пешком', 'callback_data' => 'move'],
                        ['text' => '🧑‍🌾 Действия 🛠️',   'callback_data' => 'characterActions'],
                    ],
                ]
            ];
        } else {
            // Формируем общий набор кнопок
            $text .= "Что выбираешь?\n";
            $keyboard = ['inline_keyboard' => [ $availableButtons ]];
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}
