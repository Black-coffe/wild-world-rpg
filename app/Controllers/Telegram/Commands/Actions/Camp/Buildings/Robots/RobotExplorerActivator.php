<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class RobotExplorerActivator implements RobotActivatorInterface
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterBuildingModel;
    protected $robotId;

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId                = $robotId;
    }

    public function activate($chatId, $character): ServerResponse
    {
        $characterId = $character['id'];

        // 1) Ищем все записи в crafted_items_log для данного робота (robotId), где quantity>0
        $logRows = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $this->robotId)
            ->where('quantity >', 0)
            ->findAll();

        // Если нет записей — значит роботы закончились
        if (empty($logRows)) {
            $text = "У тебя нет роботов данного типа. Возможно, они закончились или не были скрафчены.";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }


        // 2) Суммируем кол-во роботов (totalQuantity) и кол-во «остаточных» запусков (totalDurability)
        //    (quantity-1)*baseDurability + currentDurability
        $totalQuantity   = 0;
        $totalDurability = 0;

        foreach ($logRows as $row) {
            $q = (int) $row['quantity'];
            $usedDurability = (int) $row['durability_count'];

            $robotItem = $this->craftedItemsModel->find($this->robotId);
            if (!$robotItem) {
                continue;
            }
            $baseDurability = (int) $robotItem['durability_count'];

            // Остаток по одной записи
            $freshRobots   = max(0, $q - 1);
            $rowLeftover   = $freshRobots * $baseDurability + $usedDurability;

            $totalQuantity   += $q;
            $totalDurability += $rowLeftover;
        }

        // 3) Узнаём уровень мастерской (building_id=9 => "RoboticsWorkshop")
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', 9)
            ->first();

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // 4) Рассчитываем макс. время (6 ч * уровень)
        $maxHours = $workshopLevel * 6;

        // 5) Собираем текст (доп. упоминание о «Запустить рандомно» vs «Указать координаты»)
        $text = "🚀 *Ты собираешься запустить робота-исследователя!* 🔍\n\n"
            . "⚠ Одновременно может работать только *один* робот-исследователь. "
            . "Если у тебя уже запущен робот, запуск будет недоступен.\n\n"
            . "🏭 *Мастерская робототехники* даёт +6 часов исследования за каждый уровень.\n"
            . "Например, при уровне 1 — максимум 6 часов, при уровне 2 — 12 часов и т.д.\n\n"
            . "📊 *Текущая информация:*\n"
            . "🔹 Уровень *\"Мастерская робототехники\"*: *{$workshopLevel}*\n"
            . "🔹 Количество роботов данного типа: *{$totalQuantity}* шт.\n"
            . "🔹 Количество запусков (фактический остаток) по всем роботам: *{$totalDurability}*\n"
            . "🔹 При текущем уровне мастерской (уровень: *{$workshopLevel}*) "
            . "можно запускать робота максимум на *{$maxHours}* часов.\n\n"
            . "Сейчас у тебя есть *два варианта* запуска:\n"
            . "1) *Запустить рандомно* — робот начнёт исследование с неизвестной (по умолчанию) точки.\n"
            . "2) *Указать координаты* — ты сам задаёшь стартовую позицию на карте.\n\n"
            . "🎉 *Удачи в исследовании новых территорий!* 🎉";

        // 6) Делаем кнопки:
        //    — «Запустить рандомно»
        //    — «Указать координаты запуска»
        //    — «Роботы»
        // Каждую кнопку — в своей строке (два ряда + третий ряд)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Запустить рандомно', 'callback_data' => 'startRobotExplorer_' . $this->robotId],
                ],
                [
                    ['text' => '📍 Указать координаты', 'callback_data' => 'setCoordinatesRobotExplorer_' . $this->robotId],
                ],
                [
                    ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
                ],
            ]
        ];

        // 7) Отправляем
        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

