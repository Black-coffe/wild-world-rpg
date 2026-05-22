<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\ActionLogModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ExploreAreaTipsAction extends BaseAction
{
    protected $characterModel;
    protected $actionLogModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->actionLogModel = new ActionLogModel();
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

        // Получаем текущую локацию и биомы вокруг
        $mapModel = new MapModel();
        $biomeModel = new BiomeModel();
        $telegramUserModel = new TelegramUserModel();
        $chat_id = $telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
        $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        // Передаем экземпляры моделей как аргументы
        $surroundingCells = $this->getSurroundingCells($currentCell, $mapModel, $biomeModel);

        // Туман войны (ADR-019): для обучения сразу подсвечиваем клетки вокруг
        // персонажа — записываем 8 соседей в его карту (explored_cells). В реальной
        // игре они раскрываются органически при перемещении (ExploredCellsModel::revealAround).
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
                    'telegram_user_id' => $character['telegram_user_id'],
                    'map_cell_id' => $cell['cell_number'],
                    'biome_id' => $cell['biome_id'],
                    'character_level' => $character['level'],
                ]);
            }
        }

        // Формируем сообщение пользователю
        $text = $this->formatExplorationResultMessage($surroundingCells);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Завершить обучение', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '↗️ Северо-восток', 'callback_data' => 'moveNorthEastTips']
                ],
            ]
        ];
        // bugfix: было ->getChat()->getId() (chat_id) вместо callback_query_id → answerCallbackQuery
        // молча падал, «часики» на кнопке висели.
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): экран «как работает разведка / туман войны» — навигация →
        // редактируем сообщение, на котором нажата кнопка (fallback на новое при ошибке).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard),
        ]);

    }

    /**
     * Формирует сообщение для Telegram на основе исследованных ячеек.
     *
     * @param array $exploredCells Массив с информацией об исследованных ячейках.
     * @return string Отформатированное сообщение.
     */
    private function formatExplorationResultMessage($surroundingCells) {
        // Вступительное сообщение
        $message = "🤖 *Местность вокруг тебя* 🧭\n\n"
            . "_Для обучения я сразу подсветил клетки рядом с тобой — вот они:_\n\n";

        // Перебор информации о соседних ячейках и добавление данных в сообщение
        foreach ($surroundingCells as $cellInfo) {
            $message .= "📍 `{$cellInfo['coordinates']}` — 🌳 *{$cellInfo['biome']}*\n";
        }

        // Как это работает в реальной игре — ADR-019 «движение И есть разведка».
        $message .= "\n🌫️ *Туман войны.* В игре ты видишь только круг вокруг себя — 1 клетку во все стороны (включая по диагонали). Дальше — туман.\n\n"
            . "👣 *Двигаясь, ты сам открываешь местность:* каждый шаг подсвечивает клетку, в которую ты пришёл, и её 8 соседей — и они остаются на твоей карте навсегда. Просто иди и смотри.\n\n"
            . "🗺️ *В дальний путь — «Поход»:* задаёшь направление и сколько клеток пройти; идёшь сам (≈1 клетка в минуту), карта обновляется по дороге, отчёт по прибытии. Поход прервётся, если впереди что-то потребует решения (встреча, рана, чужой лагерь, край мира).\n\n"
            . "А сейчас давай сделаем твой первый переход — на «Северо-восток»! 🗺️";

        return $message;
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
                ];
            }
        }

        return $surroundingCellsInfo;
    }



}
