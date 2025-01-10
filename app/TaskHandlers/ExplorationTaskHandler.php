<?php

namespace App\TaskHandlers;

use App\Models\CharacterTaskModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use App\Models\ExploredCellsModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\WorldObjectModel;
use App\Services\PlayerDetectionService; // Подключаем PlayerDetectionService
use CodeIgniter\Controller;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

class ExplorationTaskHandler extends Controller
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
        // Обновляем статус задачи на 'completed'
        $characterTaskModel = new CharacterTaskModel();
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // Получаем данные персонажа
        $characterModel = new CharacterModel();
        $character = $characterModel->find($task['character_id']);

        // Обновляем характеристики персонажа
        $characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.01,
            'strength' => $character['strength'] + 0.03,
            'agility' => $character['agility'] + 0.01,
            'intellect' => $character['intellect'] + 0.02,
        ]);

        // Получаем текущую локацию и биомы вокруг
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();
        $telegramUserModel = new TelegramUserModel();
        $chat_id = $telegramUserModel->where('id', $task['telegram_user_id'])->first()['telegram_id'];
        $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();

        // Передаем экземпляры моделей как аргументы
        $surroundingCells = $this->getSurroundingCells($currentCell, $mapModel, $biomeModel);

        // Инициализация сервиса обнаружения объектов
        $biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $worldObjectModel = new WorldObjectModel();
        $discoveryService = new \App\Services\ObjectDiscoveryService($biomeWorldObjectMapModel, $worldObjectModel);

        // Передаем данные о ячейках в сервис и вызываем обнаружение объектов
        $discoveryService->discoverObjects($surroundingCells, $character);

        // Запись информации об изученных ячейках в базу данных
        $exploredCellsModel = new ExploredCellsModel();
        foreach ($surroundingCells as $cell) {
            // Проверяем, существует ли уже запись для данной ячейки и персонажа
            $query = $exploredCellsModel->where('character_id', $character['id'])
                ->where('map_cell_id', $cell['cell_number'])
                ->get();

            // Если запись не найдена, то вставляем новую
            if ($query->getNumRows() === 0) {
                $exploredCellsModel->insert([
                    'character_id' => $character['id'],
                    'telegram_user_id' => $task['telegram_user_id'],
                    'map_cell_id' => $cell['cell_number'],
                    'biome_id' => $cell['biome_id'],
                    'character_level' => $character['level'],
                ]);
            }
        }

        // Вызов PlayerDetectionService для обнаружения ближайших игроков
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        // Формируем сообщение пользователю
        $text = $this->formatExplorationResultMessage($surroundingCells);

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

        // Отправляем сообщение в Telegram
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => $encodedKeyboard
        ]);
    }

    private function getSurroundingCells($currentCell, MapModel $mapModel, BiomeModel $biomeModel) {
        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        // Определение координат соседних ячеек
        $neighboringPositions = [
            ['x' => $x - 1, 'y' => $y - 1], // Северо-запад
            ['x' => $x,     'y' => $y - 1], // Север
            ['x' => $x + 1, 'y' => $y - 1], // Северо-восток
            ['x' => $x - 1, 'y' => $y],     // Запад
            ['x' => $x + 1, 'y' => $y],     // Восток
            ['x' => $x - 1, 'y' => $y + 1], // Юго-запад
            ['x' => $x,     'y' => $y + 1], // Юг
            ['x' => $x + 1, 'y' => $y + 1], // Юго-восток
        ];

        $surroundingCellsInfo = [];

        foreach ($neighboringPositions as $position) {
            // Получение ячеек по координатам
            $cell = $mapModel->where('coordinate_x', $position['x'])
                ->where('coordinate_y', $position['y'])
                ->first();

            if ($cell) {
                // Получение информации о биоме
                $biome = $biomeModel->find($cell['biome_id']);
                $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                $surroundingCellsInfo[] = [
                    'cell_number' => $cell['cell_number'],
                    'coordinates' => "X={$position['x']}, Y={$position['y']}",
                    'biome' => $biomeName,
                    'biome_id' => $cell['biome_id'], // Добавить эту строку
                    'map_id' => $cell['id']  // Добавляем map_id, предполагая, что это primary key в таблице map
                ];
            }
        }

        return $surroundingCellsInfo;
    }

    private function formatExplorationResultMessage($surroundingCells) {
        // Вступительное сообщение
        $message = "*Поздравляем с успешным возвращением!* 🎉\n\n";

        // Перебор информации о соседних ячейках и добавление данных в сообщение
        foreach ($surroundingCells as $cellInfo) {
            $message .= "🌍 *Яч-ка №{$cellInfo['cell_number']}* 📍 `{$cellInfo['coordinates']}`\n"
                . "🌳 Биом: *{$cellInfo['biome']}*\n\n";
        }

        // Заключительная часть сообщения
        $message .= "_У тебя ценная информация, принимай решения оставаться здесь и выживать или переехать в новое место._\n"
            . "💡 И кстати, твои показатели немного выросли - *поздравляю!*\n\n";

        return $message;
    }

}
