<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BuildingModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class DeleteBaseAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => '🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.',
                'parse_mode' => 'Markdown'
            ]);
        }

        $callbackData = $this->callbackQuery->getData();

        if ($callbackData === 'DeleteBase_Confirm') {
            return $this->confirmDeleteBase($character);
        }

        return $this->showDeleteBaseConfirmation($character);
    }

    private function showDeleteBaseConfirmation($character): ServerResponse
    {
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Ты собираешься удалить свою базу насовсем. Вернуть это действие не получится. Все постройки, все что есть на территории базы, в том числе и твой базовый лагерь (палатка), все будет удалено навсегда. При удалении ты не получаешь никакой компенсации, и действие это необратимо. Если ты все еще не передумал и осознаешь свои действия, тогда подтверди удаление ниже кнопкой или выйди в меню персонажа.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Удалить навсегда', 'callback_data' => 'DeleteBase_Confirm'],
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function confirmDeleteBase($character): ServerResponse
    {
        $claimedCellModel = new ClaimedCellModel();
        $buildingModel = new BuildingModel();

        // Удаляем все постройки
        $characterBuildingModel = new CharacterBuildingModel();
        $buildings = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $character['cell_number'])
            ->findAll();


        foreach ($buildings as $building) {
            $buildingModel->delete($building['id']);
        }

        // Удаляем базу
        $claimedCells = $claimedCellModel->where('character_id', $character['id'])
            ->where('map_cell_id', $character['cell_number'])
            ->findAll();

        foreach ($claimedCells as $claimedCell) {
            $claimedCellModel->delete($claimedCell['id']);
        }

        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Ты успешно удалил свою базу со всеми ее сооружениями. Мы надеемся, что ты хорошо подумал перед принятием этого решения. Если тебе понадобится помощь, я всегда здесь, чтобы помочь.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
