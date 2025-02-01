<?php

namespace App\TaskHandlers;

use App\Models\CharacterTaskModel;
use App\Models\CharacterModel;
use App\Models\CharacterBuildingModel;
use App\Models\MapModel;
use App\Models\ExploredCellsModel;
use App\Models\BiomeModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\WorldObjectModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerDetectionService;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Обработчик завершения задачи исследования робота.
 *
 * Если в task_settings переданы координаты в формате "X,Y",
 * используется круговое исследование (от центра по кругу).
 * Иначе берётся стартовая точка, соответствующая cell_number персонажа, и применяется алгоритм «змейки».
 */
class CompleteRobotExplorationHandler extends Controller
{
    private $telegram;
    private $playerDetectionService;

    public function __construct()
    {
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            // Инициализация Telegram-объекта и Request
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }

        // Сервис обнаружения ближайших игроков (PvP-логика)
        $this->playerDetectionService = new PlayerDetectionService();
    }

    /**
     * Основной метод, вызываемый при завершении задачи (character_tasks).
     * @param array $task — запись из character_tasks (с полями id, character_id, start_time, end_time, task_settings и т.п.)
     */
    public function handle($task)
    {
        // 1) Подключаем модели
        $characterTaskModel      = new CharacterTaskModel();
        $characterModel          = new CharacterModel();
        $characterBuildingModel  = new CharacterBuildingModel();
        $mapModel                = new MapModel();
        $exploredCellsModel      = new ExploredCellsModel();
        $biomeModel              = new BiomeModel();
        $biomeWorldObjectMapModel= new \App\Models\BiomeWorldObjectMapModel();
        $worldObjectModel        = new WorldObjectModel();
        $telegramUserModel       = new TelegramUserModel();

        // 2) Ставим задаче статус = 'completed'
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 3) Получаем персонажа и Telegram-данные
        $character = $characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', 'Не найден персонаж при закрытии задачи исследования.');
            return false;
        }

        $chatRow = $telegramUserModel->where('id', $task['telegram_user_id'])->first();
        if (!$chatRow) {
            log_message('error', 'Не найден Telegram-пользователь для отправки сообщения.');
            return false;
        }
        $chatId = $chatRow['telegram_id'];

        // 4) Вычисляем, сколько часов прошло
        $startTime  = strtotime($task['start_time']);
        $endTime    = strtotime($task['end_time']);
        $hoursSpent = max(0, ($endTime - $startTime) / 3600);

        // 5) Определяем уровень мастерской (например, RoboticsWorkshop; здесь для примера используется building_id = 999)
        $workshopLevel = 1;
        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', 999)
            ->first();
        if ($roboticsWorkshop) {
            $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        }

        // 6) Рассчитываем количество ячеек для открытия
        $cellsPerHour = 50 + max(0, $workshopLevel - 1) * 10;
        $cellsToOpen  = (int) floor($hoursSpent * $cellsPerHour);

        // 7) Проверяем, заданы ли стартовые координаты в task_settings (ожидается формат "X,Y")
        $startCoords = null;
        if (!empty($task['task_settings'])) {
            $pattern = '/^(\d+),(\d+)$/';
            if (preg_match($pattern, $task['task_settings'], $m)) {
                $xVal = (int) $m[1];
                $yVal = (int) $m[2];
                if ($xVal >= 1 && $xVal <= 1000 && $yVal >= 1 && $yVal <= 1000) {
                    $startCoords = ['x' => $xVal, 'y' => $yVal];
                }
            }
        }

        // 8) Определяем метод исследования: если указаны стартовые координаты – круговое исследование, иначе – старый алгоритм "змейки"
        if ($startCoords !== null) {
            $newCells = $this->calculateNewCellsCircle(
                $character,
                $cellsToOpen,
                $mapModel,
                $exploredCellsModel,
                $task,
                $startCoords
            );
        } else {
            $newCells = $this->calculateNewCellsSnake(
                $character,
                $cellsToOpen,
                $mapModel,
                $biomeModel,
                $exploredCellsModel,
                $task,
                $startCoords
            );
        }

        // 9) Формируем итоговое сообщение
        $text = $this->formatExplorationResultMessage($newCells, $hoursSpent, $cellsToOpen);

        // 10) Inline-кнопки для дальнейших действий
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚜 Переехать',  'callback_data' => 'move'],
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                ],
            ]
        ];

        // 11) Отправляем сообщение в Telegram
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 12) Запускаем PvP-обнаружение
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return true;
    }

    /**
     * Алгоритм "змейки" (старый вариант) с движением по горизонтали.
     */
    private function calculateNewCellsSnake(
        array $character,
        int $cellsToOpen,
        MapModel $mapModel,
        BiomeModel $biomeModel,
        ExploredCellsModel $exploredCellsModel,
        array $task,
        ?array $startCoords = null
    ): array {
        $newCells = [];
        if ($startCoords !== null) {
            $x = $startCoords['x'];
            $y = $startCoords['y'];
        } else {
            $currentCell = $mapModel
                ->where('cell_number', $character['cell_number'])
                ->first();
            if (!$currentCell) {
                return $newCells;
            }
            $x = $currentCell['coordinate_x'];
            $y = $currentCell['coordinate_y'];
        }

        $goingRight = true;
        $openedCount = 0;
        $characterId    = $character['id'];
        $telegramUserId = $task['telegram_user_id'];

        while ($openedCount < $cellsToOpen) {
            if ($y < 0) {
                break;
            }
            if ($x == 1 && $y == 1) {
                $y = 1000;
            }

            $cell = $mapModel
                ->where('coordinate_x', $x)
                ->where('coordinate_y', $y)
                ->first();

            if ($cell) {
                $alreadyOpened = $exploredCellsModel
                    ->where('character_id', $characterId)
                    ->where('map_cell_id', $cell['cell_number'])
                    ->first();

                if (!$alreadyOpened) {
                    $biome     = $biomeModel->find($cell['biome_id']);
                    $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                    $newCells[] = [
                        'cell_number' => $cell['cell_number'],
                        'coordinates' => "X={$x}, Y={$y}",
                        'biome'       => $biomeName,
                        'biome_id'    => $cell['biome_id'],
                    ];

                    $exploredCellsModel->insert([
                        'character_id'     => $characterId,
                        'telegram_user_id' => $telegramUserId,
                        'map_cell_id'      => $cell['cell_number'],
                        'biome_id'         => $cell['biome_id'],
                        'character_level'  => $character['level'],
                    ]);

                    $openedCount++;
                    if ($openedCount >= $cellsToOpen) {
                        break;
                    }
                }
            }
            if ($goingRight) {
                if ($x < 1000) {
                    $x++;
                } else {
                    $y--;
                    $goingRight = false;
                }
            } else {
                if ($x > 0) {
                    $x--;
                } else {
                    $y--;
                    $goingRight = true;
                }
            }
        }

        return $newCells;
    }

    /**
     * Новый метод кругового исследования ячеек.
     *
     * Если заданы стартовые координаты, то от них выбираются точки по кругу.
     * При этом не проверяются занятость ячейки чужой базой или изученность – все ячейки в круге помечаются как изученные.
     *
     * @param array $character Данные персонажа.
     * @param int $cellsToOpen Количество ячеек для открытия.
     * @param MapModel $mapModel Модель карты.
     * @param ExploredCellsModel $exploredCellsModel Модель изученных ячеек.
     * @param array $task Данные задачи (для telegram_user_id).
     * @param array|null $startCoords Стартовые координаты в виде ['x' => ..., 'y' => ...].
     * @return array Массив найденных ячеек.
     */
    private function calculateNewCellsCircle(
        array $character,
        int $cellsToOpen,
        MapModel $mapModel,
        ExploredCellsModel $exploredCellsModel,
        array $task,
        ?array $startCoords = null
    ): array {
        $newCells = [];

        // Определяем центр поиска
        if ($startCoords !== null) {
            $centerX = $startCoords['x'];
            $centerY = $startCoords['y'];
        } else {
            $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();
            if (!$currentCell) {
                return $newCells;
            }
            $centerX = $currentCell['coordinate_x'];
            $centerY = $currentCell['coordinate_y'];
        }

        // Максимальный радиус – до края карты от центра
        $maxRadius = max($centerX - 1, 1000 - $centerX, $centerY - 1, 1000 - $centerY);
        $visited = [];
        $characterId = $character['id'];
        $telegramUserId = $task['telegram_user_id'];

        // Перебираем радиусы от 0 до maxRadius, выбирая точки по кругу
        for ($r = 0; $r <= $maxRadius && count($newCells) < $cellsToOpen; $r++) {
            $steps = 36; // шаг по углам (каждые 10°)
            for ($i = 0; $i < $steps && count($newCells) < $cellsToOpen; $i++) {
                $angle = (2 * pi() * $i) / $steps;
                $xCandidate = (int) round($centerX + $r * cos($angle));
                $yCandidate = (int) round($centerY + $r * sin($angle));

                // Ограничиваем координаты диапазоном [1, 1000]
                $xCandidate = max(1, min(1000, $xCandidate));
                $yCandidate = max(1, min(1000, $yCandidate));

                $key = $xCandidate . '_' . $yCandidate;
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;

                $cell = $mapModel->where('coordinate_x', $xCandidate)
                    ->where('coordinate_y', $yCandidate)
                    ->first();
                if (!$cell) {
                    continue;
                }

                // Здесь мы не проверяем занятость или изученность – отмечаем все ячейки
                $biomeModel = new BiomeModel();
                $biome = $biomeModel->find($cell['biome_id']);
                $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                $newCells[] = [
                    'cell_number' => $cell['cell_number'],
                    'coordinates' => "X={$xCandidate}, Y={$yCandidate}",
                    'biome'       => $biomeName,
                    'biome_id'    => $cell['biome_id'],
                ];

                // Пытаемся вставить запись в explored_cells, если её ещё нет
                $existing = $exploredCellsModel
                    ->where('character_id', $characterId)
                    ->where('map_cell_id', $cell['cell_number'])
                    ->first();
                if (!$existing) {
                    $exploredCellsModel->insert([
                        'character_id'     => $characterId,
                        'telegram_user_id' => $telegramUserId,
                        'map_cell_id'      => $cell['cell_number'],
                        'biome_id'         => $cell['biome_id'],
                        'character_level'  => $character['level'],
                    ]);
                }
            }
        }
        return $newCells;
    }

    /**
     * Формирует итоговое сообщение с результатами исследования.
     */
    private function formatExplorationResultMessage(
        array $newCells,
        float $hoursSpent,
        int $cellsToOpen
    ): string {
        $countOpened = count($newCells);
        $biomeNames   = array_column($newCells, 'biome');
        $uniqueBiomes = array_unique($biomeNames);

        $wholeHours = floor($hoursSpent);
        $decimalPart = $hoursSpent - $wholeHours;
        $minutes = floor($decimalPart * 60);
        $hoursSpentFormatted = "{$wholeHours} ч {$minutes} мин";

        $msg = "🚀 *Исследование роботом завершено!* 🤖\n\n"
            . "Задача длилась примерно: `{$hoursSpentFormatted}`.\n"
            . "Планировалось открыть *{$cellsToOpen}* ячеек,\n"
            . "Фактически удалось открыть: *{$countOpened}*\n\n";

        if ($countOpened > 0) {
            $msg .= "Среди них встречаются биомы:\n";
            foreach ($uniqueBiomes as $b) {
                $msg .= " - *{$b}*\n";
            }
        } else {
            $msg .= "_К сожалению, не удалось найти новые ячейки._\n";
        }

        $msg .= "\n🔎 Надеюсь, эти данные помогут тебе в дальнейшем путешествии!";
        return $msg;
    }
}
