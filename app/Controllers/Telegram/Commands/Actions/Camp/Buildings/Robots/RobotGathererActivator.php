<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Services\Player\RobotService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Bases\BaseCheckService;
use App\Services\Coverage\CommunicationTowerCoverageService;

/**
 * Класс для отображения/активации робота-добытчика ресурсов (Resource Gatherer).
 */
class RobotGathererActivator implements RobotActivatorInterface
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterBuildingModel;
    protected $robotId;
    protected RobotService $robotService;

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId                = $robotId;
        $this->robotService           = new RobotService();
    }

    /**
     * Метод активации робота-добытчика.
     *
     * @param int   $chatId
     * @param array|\App\Entities\CharacterEntity $character — данные персонажа (из CharacterModel).
     * @return ServerResponse
     */
    public function activate($chatId, $character): ServerResponse
    {
        $characterId = $character['id'];

        // 0. Проверяем, есть ли вообще база у игрока
        $baseCheckService = new BaseCheckService();
        $baseStatus = $baseCheckService->checkBaseStatus($characterId);
        if (!$baseStatus['hasBase']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У тебя нет базы! Сначала построй её, чтобы запустить робота-добытчика."
            ]);
        }

        // 1. Если игрок не на базе физически, проверяем наличие «дистанционного управления»
        if (!$baseStatus['isOnBase']) {
            $coverageService = new CommunicationTowerCoverageService();
            $coverageResult  = $coverageService->checkCoverage($characterId);
            if (!$coverageResult['isCovered']) {
                // Ни физически, ни через вышку связи => отказываем
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => "Ты не находишься на своей базе, и сигнал Вышки связи не покрывает твоё текущее положение. "
                        . "Пройдись до базы или окажись в зоне связи!",
                ]);
            }
            // Если покрытие есть — продолжаем код: считаем, что «виртуально» мы имеем доступ к базе
        }

        // --- ниже идёт уже ваш прежний код ---
        // 2) Проверяем, есть ли в crafted_items_log роботы данного типа
        $logRows = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $this->robotId)
            ->where('quantity >', 0)
            ->findAll();

        if (empty($logRows)) {
            $text = "У тебя нет роботов-добытчиков данного типа. Возможно, они закончились или не были скрафчены.";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        // 2-7) Уровень мастерской, охват, редкость, остаток запусков, caption —
        // chat-requests-batch-09 review fix (BLOCK #3): вынесены в отдельный
        // testable `buildCaption()`, чтобы тест мог проверить РЕНДЕР текста
        // (не только приватный делегат формулы в отрыве от экрана).
        $text = $this->buildCaption($characterId, $logRows);

        // 8) Кнопки: «Запуск робота», (V19) «Ремонт» если частично израсходован, «Назад»
        $rows = [
            [['text' => '🚀 Запуск робота', 'callback_data' => 'startRobotGatherer_' . $this->robotId]],
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

        $rows[] = [['text' => '◀️ Назад', 'callback_data' => 'AllRobots']];
        $keyboard = ['inline_keyboard' => $rows];

        // 9) Отправляем результат
        $imagePath = base_url('uploads/telegram/craft/standard/robot_gatherer.jpg'); // условный путь к картинке

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Chat-requests-batch-09 review fix (BLOCK #2, #3): caption экрана
     * активации, вынесен из `activate()` в отдельный testable метод (без
     * похода в Telegram) — иначе занижение охвата ловится только на приватном
     * делегате формулы в отрыве от реального текста, что и было причиной
     * BLOCK-вердикта ревью.
     *
     * Охват — ЕДИНСТВЕННЫЙ источник `RobotService::gatheringReachCells()`
     * (`max(1, workshopLevel + extraCells)`, до story 09 здесь стоял голый
     * `$workshopLevel`). Имя робота — реальное `crafted_items.name_rus`, а не
     * зашитое «Робот-добытчик» (Max Syskov, 19.08.2026: «у меня промышленник,
     * но в сообщении добытчик» — до этого фикса был жив на ДВУХ из трёх
     * экранов: этом и `StartRobotGatheringAction`).
     *
     * @param array<int,array<string,mixed>> $logRows строки `crafted_items_log`
     *                                                 для `$this->robotId` (уже отфильтрованы quantity>0)
     */
    private function buildCaption(int $characterId, array $logRows): string
    {
        $robotItem = $this->craftedItemsModel->find($this->robotId);
        $baseDurability = is_array($robotItem) && isset($robotItem['durability_count']) && is_numeric($robotItem['durability_count'])
            ? (int) $robotItem['durability_count']
            : 0;
        $robotNameEn = is_array($robotItem) && isset($robotItem['name_eng']) && is_string($robotItem['name_eng'])
            ? $robotItem['name_eng']
            : null;
        $robotDisplayName = is_array($robotItem) && isset($robotItem['name_rus']) && is_string($robotItem['name_rus']) && $robotItem['name_rus'] !== ''
            ? $robotItem['name_rus']
            : 'Робот-добытчик';

        // Суммируем общее количество роботов и «фактический» остаток запусков.
        $totalQuantity   = 0;
        $totalDurability = 0;
        foreach ($logRows as $row) {
            $q              = isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : 0;
            $usedDurability = isset($row['durability_count']) && is_numeric($row['durability_count']) ? (int) $row['durability_count'] : 0;
            $freshRobots    = max(0, $q - 1);

            $totalQuantity   += $q;
            $totalDurability += $freshRobots * $baseDurability + $usedDurability;
        }

        // Уровень мастерской робототехники — id по стабильному name_en (E28, не хардкод 9).
        $roboticsId       = (new BuildingModel())->idByNameEn('RoboticsWorkshop');
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsId)
            ->first();
        $workshopLevel = is_array($roboticsWorkshop) && isset($roboticsWorkshop['level']) && is_numeric($roboticsWorkshop['level'])
            ? (int) $roboticsWorkshop['level']
            : 1;

        // Макс. время = 2 часа * уровень мастерской.
        $maxHours = 2 * $workshopLevel;

        // Диапазон редкости ресурсов: уровень 1 => только "10", уровень 2 => 10..9, и т.д.
        $minRarity = max(1, 10 - ($workshopLevel - 1));

        // Охват — единственный источник RobotService::gatheringReachCells().
        $cellsCount     = $this->robotService->gatheringReachCells($workshopLevel, $robotNameEn);
        $cellsCountNext = $this->robotService->gatheringReachCells($workshopLevel + 1, $robotNameEn);

        return "⚙️ *{$robotDisplayName}* ⚙️\n\n"
            . "🚫 *Внимание:* робот привязан строго к координате твоей базы, рандомно выбрать точку нельзя.\n\n"
            . "🏭 Мастерская робототехники (уровень: *{$workshopLevel}*):\n"
            . "   • даёт *{$maxHours}* часов работы (2 часа на уровень)\n"
            . "   • позволяет собирать ресурсы редкости от *10* до *{$minRarity}*\n"
            . "   • обходит *{$cellsCount}* яч. вокруг базы (на след. уровне мастерской — *{$cellsCountNext}*)\n\n"
            . "📊 *Текущая информация:*\n"
            . "   • Количество роботов‐добытчиков данного типа: *{$totalQuantity}*\n"
            . "   • Суммарный остаток запусков: *{$totalDurability}*\n"
            . "   • Одновременно может работать только один робот‐добытчик (по умолчанию).\n\n"
            . "🎉 Готов к сбору? Жми «Запуск робота» ниже!";
    }
}
