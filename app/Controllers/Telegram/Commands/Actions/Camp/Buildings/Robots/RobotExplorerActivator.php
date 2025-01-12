<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class RobotExplorerActivator implements RobotActivatorInterface
{
    protected $craftedItemsLogModel;
    protected $characterBuildingModel;
    protected $robotId;

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId = $robotId;
    }

    public function activate($chatId, $character): ServerResponse
    {
        $characterId = $character['id'];

        // Получаем количество роботов и их использований
        $robotData = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $this->robotId) // Используем переданный ID робота
            ->select('SUM(quantity) as total_quantity, SUM(durability_count * quantity) as total_durability')
            ->first();

        $totalQuantity = $robotData['total_quantity'] ?? 0;
        $totalDurability = $robotData['total_durability'] ?? 0;

        // Получаем уровень мастерской робототехники
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', 9) // Замените 9 на реальный ID мастерской робототехники
            ->first();

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // Рассчитываем время до поломки робота
        $hoursUntilBreakdown = $totalDurability * $workshopLevel;

        $text = "🚀 *Ты собираешься активировать работу робота Исследователя!* 🔍\n\n"
            . "🔧 Данный робот будет запущен и отработает ровно до момента пока у него количество использований не закончится.\n\n"
            . "🏭 *Мастерская робототехники* влияет на эффективность использования робота:\n"
            . "1 уровень = 1 час = 1 единица использования.\n"
            . "10 уровень = 10 часов = 1 единица использования.\n\n"
            . "📊 *Текущая информация:*\n"
            . "🔹 Уровень *\"Мастерская робототехники\"*: *{$workshopLevel}*\n"
            . "🔹 Количество роботов данного типа: *{$totalQuantity}* шт.\n"
            . "🔹 Количество использований: *{$totalDurability}* раз\n"
            . "🔹 При текущем уровне \"Мастерская робототехники\" робот поломается и исчезнет через: *{$hoursUntilBreakdown}* часов\n\n"
            . "🎉 *Удачи в исследовании новых территорий!* 🎉";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Запустить', 'callback_data' => 'startRobotExplorer_'.$this->robotId],
                    ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
