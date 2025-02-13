<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Request;
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

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId                = $robotId;
    }

    /**
     * Метод активации робота-добытчика.
     *
     * @param int   $chatId
     * @param array $character — данные персонажа (из CharacterModel).
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

        // 2) Суммируем общее количество роботов и «фактический» остаток запусков
        $totalQuantity   = 0;
        $totalDurability = 0;

        foreach ($logRows as $row) {
            $q              = (int) $row['quantity'];
            $usedDurability = (int) $row['durability_count'];

            $robotItem = $this->craftedItemsModel->find($this->robotId);
            if (!$robotItem) {
                continue;
            }
            $baseDurability = (int) $robotItem['durability_count'];

            // Формула, аналогичная исследователю
            $freshRobots  = max(0, $q - 1);
            $rowLeftover  = $freshRobots * $baseDurability + $usedDurability;

            $totalQuantity   += $q;
            $totalDurability += $rowLeftover;
        }

        // 3) Узнаём уровень мастерской (id=9 для RoboticsWorkshop, по аналогии)
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', 9)
            ->first();

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // 4) Рассчитываем макс. время = 2 часа * уровень мастерской
        $maxHours = 2 * $workshopLevel;

        // 5) Влияет на диапазон редкости ресурсов, например:
        //    Уровень мастерской 1 => только редкость "10"
        //    Уровень 2 => 10..9
        //    Уровень 3 => 10..8
        //    и т.д.
        //    Вычислим нижнюю границу (minRarity)
        $minRarity = 10 - ($workshopLevel - 1);
        if ($minRarity < 1) {
            $minRarity = 1; // чтобы не было меньше 1
        }

        // 6) Число ячеек вокруг базы = уровень мастерской
        //    Если уровень=1, то робот собирает только из ячейки, где находится база
        //    Если уровень=3, то захватывает базовую + ещё 2 круга вокруг и т.п.
        //    (Подробная логика сбора ресурсов будет в классе старта/завершения задачи)
        $cellsCount = $workshopLevel;

        // 7) Формируем описание для пользователя
        $text = "⚙️ *Робот-добытчик ресурсов!* ⚙️\n\n"
            . "🚫 *Внимание:* робот привязан строго к координате твоей базы, рандомно выбрать точку нельзя.\n\n"
            . "🏭 Мастерская робототехники (уровень: *{$workshopLevel}*):\n"
            . "   • даёт *{$maxHours}* часов работы (2 часа на уровень)\n"
            . "   • позволяет собирать ресурсы редкости от *10* до *{$minRarity}*\n"
            . "   • обходит *{$cellsCount}* яч. вокруг базы\n\n"
            . "📊 *Текущая информация:*\n"
            . "   • Количество роботов‐добытчиков данного типа: *{$totalQuantity}*\n"
            . "   • Суммарный остаток запусков: *{$totalDurability}*\n"
            . "   • Одновременно может работать только один робот‐добытчик (по умолчанию).\n\n"
            . "🎉 Готов к сбору? Жми «Запуск робота» ниже!";

        // 8) Две кнопки: «Запуск робота» и «Назад»
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text'          => '🚀 Запуск робота',
                        'callback_data' => 'startRobotGatherer_' . $this->robotId,
                    ],
                    [
                        'text'          => '◀️ Назад',
                        'callback_data' => 'AllRobots',
                    ],
                ],
            ]
        ];

        // 9) Отправляем результат
        $imagePath = base_url('uploads/telegram/craft/standard/robot_gatherer.jpg'); // условный путь к картинке

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
