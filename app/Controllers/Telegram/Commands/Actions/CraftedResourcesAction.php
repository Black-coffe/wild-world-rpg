<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;

class CraftedResourcesAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items.name_rus, crafted_items.type, crafted_items.durability_count')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $character['id'])
            ->findAll();

        $typeIcons = [
            'tool' => '🛠️',
            'drug' => '💊',
            'component' => '🧩',
            'transport' => '🛤',
            'workbench' => '🔬',
            'robots' => '🤖',
            // Добавьте другие типы и иконки здесь
        ];

        if (empty($craftedItemsLogs)) {
            $text = "🤷‍♂️ *Не переживай, друг!* Твои усилия в крафтинге всё ещё впереди.\n\n"
                . "Просто выбери рецепт и начни создавать что-то великолепное! 🗝️💎\n\n"
                . "И помни, каждый великий мастер начинал с малого! 🌟";
        } else {
            $text = "*Склад крафтовых предметов:*\n\n";

            foreach ($craftedItemsLogs as $item) {
                $icon = $typeIcons[$item['type']] ?? '🔧'; // Используем заданную иконку или 🔧 как иконку по умолчанию
                $text .= $icon . " " . $item['name_rus'] . " | " . number_format($item['quantity']) . " шт. " . number_format($item['quantity']*$item['durability_count']) . " исп.\n";
            }

            $text .= "\n*Продолжай в том же духе и твои навыки станут легендарными!*";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ],
                [
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop']
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}
