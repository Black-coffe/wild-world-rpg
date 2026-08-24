<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

// 1. Подключаем нужные сервисы
use App\Services\Bases\BaseCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * Класс RobotExplorerActivator для вывода информации и активации робота-исследователя.
 */
class RobotExplorerActivator implements RobotActivatorInterface
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterBuildingModel;
    protected $robotId;

    // (Необязательно) Можно хранить сервисы в полях класса,
    // чтобы не создавать их каждый раз в методе
    protected $baseCheckService;
    protected $coverageService;

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId                = $robotId;

        // 2. Создаём инстансы сервисов (по желанию — прямо в методе activate)
        $this->baseCheckService       = new BaseCheckService();
        $this->coverageService        = new CommunicationTowerCoverageService();
    }

    /**
     * Метод активации робота-исследователя (показывает инфу и кнопки «Запуск»).
     *
     * @param int   $chatId
     * @param array|\App\Entities\CharacterEntity $character
     * @return ServerResponse
     */
    public function activate($chatId, $character): ServerResponse
    {
        $characterId = $character['id'];

        // 3. Сначала проверяем, есть ли база, и где персонаж
        $baseStatus = $this->baseCheckService->checkBaseStatus($characterId);

        if (!$baseStatus['hasBase']) {
            // У игрока вообще нет базы
            $text = "У тебя нет базы! Сначала построи базу, чтобы запускать роботов-исследователей.";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        // Если персонаж НЕ на базе, но возможно есть вышка связи
        if (!$baseStatus['isOnBase']) {
            $coverageResult = $this->coverageService->checkCoverage($characterId);
            if (!$coverageResult['isCovered']) {
                // Ни физически, ни через вышку связи — отказываем
                $text = "Ты не на своей базе и сигнал Вышки связи сюда не дотягивается. "
                    . "Вернись на базу или в зону покрытия, чтобы запустить робота-исследователя!";
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => $text,
                ]);
            }
            // Если покрытие есть — продолжим, как будто игрок «виртуально» имеет доступ к базе
        }

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

        // 3) Уровень мастерской робототехники — id по стабильному name_en (E28, не хардкод 9)
        $roboticsId       = (new BuildingModel())->idByNameEn('RoboticsWorkshop');
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsId)
            ->first();

        $hasWorkshop   = is_array($roboticsWorkshop);
        $workshopLevel = $hasWorkshop && isset($roboticsWorkshop['level']) && is_numeric($roboticsWorkshop['level']) ? (int) $roboticsWorkshop['level'] : 1;

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
        //    — (V19) «Ремонт», если текущий робот частично израсходован
        //    — «Роботы»
        // UX-DISCOVERABILITY: без мастерской вход не прячется — lock-кнопка, клик объясняет, что строить.
        if (!$hasWorkshop) {
            $text = "🔍 *Робот-исследователь*\n\n"
                . "📊 Роботов данного типа: *{$totalQuantity}*, остаток запусков: *{$totalDurability}*.\n\n"
                . StartRobotGatheringAction::noWorkshopMessage();
        }
        $rows = $hasWorkshop
            ? [
                [['text' => '🚀 Запустить рандомно', 'callback_data' => 'startRobotExplorer_' . $this->robotId]],
                [['text' => '📍 Указать координаты', 'callback_data' => 'setCoordinatesRobotExplorer_' . $this->robotId]],
            ]
            : [
                [['text' => '🔒 Нужна мастерская', 'callback_data' => 'startRobotExplorer_' . $this->robotId]],
            ];

        // V19 (ADR-050): кнопка ремонта, если есть частично израсходованный робот этого типа.
        if ((new \App\Services\Player\RobotRepairService())->enabled()) {
            $robotItem = $this->craftedItemsModel->find($this->robotId);
            $baseDur   = is_array($robotItem) && isset($robotItem['durability_count']) && is_numeric($robotItem['durability_count'])
                ? (int) $robotItem['durability_count'] : 0;
            $needsRepair = false;
            foreach ($logRows as $lr) {
                $d = isset($lr['durability_count']) && is_numeric($lr['durability_count']) ? (int) $lr['durability_count'] : 0;
                if ($baseDur > 0 && $d < $baseDur) {
                    $needsRepair = true;
                    break;
                }
            }
            if ($needsRepair) {
                $rows[] = [['text' => '🔧 Ремонт', 'callback_data' => 'robotRepair_' . $this->robotId]];
            }
        }

        $rows[] = [['text' => '🤖 Роботы', 'callback_data' => 'AllRobots']];
        $keyboard = ['inline_keyboard' => $rows];

        // 7) Отправляем
        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}

