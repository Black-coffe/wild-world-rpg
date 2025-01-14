<?php

namespace App\TaskHandlers;

use App\Models\BiomeModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Models\WorldObjectModel;
use App\Services\Player\PlayerDetectionService;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Подключаем PlayerDetectionService

class CompleteRobotExplorationHandler extends Controller
{
    private $telegram;
    private $playerDetectionService; // Добавляем свойство для PlayerDetectionService

    public function __construct()
    {
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Инициализируем объект Telegram в Request
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            // Обработка исключений при инициализации бота
            log_message('error', $e->getMessage());
        }

        // Инициализация PlayerDetectionService
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle($task)
    {
        // Модели
        $characterTaskModel = new CharacterTaskModel();
        $characterModel = new CharacterModel();
        $craftedItemsLogModel = new CraftedItemsLogModel();
        $characterBuildingModel = new CharacterBuildingModel();
        $exploredCellsModel = new ExploredCellsModel();
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();
        $telegramUserModel = new TelegramUserModel();
        $biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $worldObjectModel = new WorldObjectModel();

        // Обновляем статус задачи на 'completed'
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Получаем данные персонажа
        $character = $characterModel->find($task['character_id']);
        $chatId = $telegramUserModel->where('id', $task['telegram_user_id'])->first()['telegram_id'];

        // Проверяем количество роботов и их использований
        $robotId = $task['task_id'];
        $robotData = $craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->where('crafted_item_id', $robotId)
            ->select('SUM(quantity) as total_quantity, SUM(durability_count * quantity) as total_durability')
            ->first();

        $totalQuantity = $robotData['total_quantity'] ?? 0;
        $totalDurability = $robotData['total_durability'] ?? 0;

        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', function ($builder) {
                $builder->select('id')
                    ->from('buildings')
                    ->where('name_en', 'RoboticsWorkshop'); // Проверим правильность столбца
            })
            ->first();

        if (!$roboticsWorkshop) {
            return $this->sendError($chatId, 'Мастерская робототехники не найдена.');
        }

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        $hoursSpent = (strtotime($task['end_time']) - strtotime($task['start_time'])) / 3600;

        if ($totalQuantity <= 0 || $hoursSpent > ($totalDurability * $workshopLevel)) {
            return $this->sendError($chatId, 'Количество роботов или их использований недостаточно для завершения задачи.');
        }

        // Обновляем количество роботов и их использований
        $unitsUsed = $hoursSpent / $workshopLevel;
        $unitsToDeduct = ceil($unitsUsed);

        if ($totalDurability < $unitsToDeduct) {
            return $this->sendError($chatId, 'Недостаточно использований роботов для завершения задачи.');
        }

        // Обновляем таблицу логов с роботами
        $craftedItemsLogModel->where('character_id', $character['id'])
            ->where('crafted_item_id', $robotId)
            ->decrement('durability_count', $unitsToDeduct);

        // Записываем новые изученные ячейки
        $newCells = $this->calculateNewCells($character, $hoursSpent, $mapModel, $biomeModel, $exploredCellsModel);

        // Отправляем сообщение пользователю
        $text = $this->formatExplorationResultMessage($newCells, $totalQuantity, $unitsToDeduct);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                ],
                [
                    ['text' => '📜 Выполнить квест', 'callback_data' => 'quest'],
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                ],
            ]
        ];
        $encodedKeyboard = json_encode($keyboard);

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);

        // Интеграция PlayerDetectionService: вызываем метод для обнаружения ближайших игроков
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return true;
    }

    private function sendError($chatId, $message)
    {
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ]);
    }

    private function calculateNewCells($character, $hoursSpent, $mapModel, $biomeModel, $exploredCellsModel)
    {
        $newCells = [];
        $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        for ($i = 0; $i < $hoursSpent; $i++) {
            // Получение координат новой ячейки
            $newCell = $this->getNextCellCoordinates($x, $y, $i);
            $cell = $mapModel->where('coordinate_x', $newCell['x'])
                ->where('coordinate_y', $newCell['y'])
                ->first();

            if ($cell && !$exploredCellsModel->where('character_id', $character['id'])
                    ->where('map_cell_id', $cell['cell_number'])
                    ->first()) {
                // Получение информации о биоме
                $biome = $biomeModel->find($cell['biome_id']);
                $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                $newCells[] = [
                    'cell_number' => $cell['cell_number'],
                    'coordinates' => "X={$newCell['x']}, Y={$newCell['y']}",
                    'biome' => $biomeName,
                    'biome_id' => $cell['biome_id'],
                ];

                // Запись в базу данных
                $exploredCellsModel->insert([
                    'character_id' => $character['id'],
                    'telegram_user_id' => $character['telegram_user_id'],
                    'map_cell_id' => $cell['cell_number'],
                    'biome_id' => $cell['biome_id'],
                    'character_level' => $character['level'],
                ]);
            }
        }

        return $newCells;
    }

    private function getNextCellCoordinates($x, $y, $step)
    {
        // Простая формула для получения следующей ячейки (по спирали или другой логике)
        return ['x' => $x + $step, 'y' => $y + $step]; // Замените на нужную логику
    }

    private function formatExplorationResultMessage($newCells, $totalQuantity, $unitsToDeduct)
    {
        $uniqueBiomes = array_unique(array_column($newCells, 'biome'));

        $message = "🚀 *Робот завершил успешно изучение локаций!* 🔍\n\n"
            . "Ты получил информацию о: *" . count($newCells) . "* новых ячейках\n\n"
            . "В том числе открыл такие биомы:\n";

        foreach ($uniqueBiomes as $biome) {
            $message .= "🌳 *{$biome}*\n";
        }

        $remainingQuantity = $totalQuantity - $unitsToDeduct;

        $message .= "\n_У тебя ценная информация, принимай решения оставаться здесь и выживать или переехать в новое место._\n\n"
            . "В результате изучения у тебя было списано: *{$unitsToDeduct}* робота.\n"
            . "Осталось в наличии: *{$remainingQuantity}* шт.\n";

        return $message;
    }
}
