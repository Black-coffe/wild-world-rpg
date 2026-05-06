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
use Config\GameBalance;

/**
 * Обработчик завершения задачи исследования робота.
 *
 * Если в task_settings переданы координаты в формате "X,Y",
 * используется круговое исследование (от центра по кругу).
 * Иначе берётся стартовая точка, соответствующая cell_number персонажа, и применяется алгоритм «змейки».
 *
 * v0.51.21 (F2.9 batch-3): extends BaseTaskHandler (per F2.9 contract).
 * Раніше extends Controller — handler НЕ контроллер.
 * Telegram lazy-init через BaseTaskHandler::telegram(), Request::sendMessage → safeSendMessage.
 * `handle($task)` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 *
 * v0.51.24 (C/F6 expansion): roboticsExplorationCellsBase + cellsPerLevel читаються
 * через config('GameBalance'). Раніше hardcoded `50 + (level-1) * 10` formula.
 */
class CompleteRobotExplorationHandler extends BaseTaskHandler
{
    private PlayerDetectionService $playerDetectionService;
    private GameBalance $cfg;

    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
        // Сервис обнаружения ближайших игроков (PvP-логика)
        $this->playerDetectionService = new PlayerDetectionService();
    }

    /**
     * Основной метод, вызываемый при завершении задачи (character_tasks).
     *
     * @param array<string,mixed> $task — запись из character_tasks (з полями id, character_id, start_time, end_time, task_settings).
     */
    public function handle(array $task = []): void
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
            return;
        }

        $chatRow = $telegramUserModel->where('id', $task['telegram_user_id'])->first();
        if (!$chatRow) {
            log_message('error', 'Не найден Telegram-пользователь для отправки сообщения.');
            return;
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
        // v0.51.24: base + (level-1) × perLevel читається через GameBalance
        $cellsPerHour = $this->cfg->roboticsExplorationCellsBase
                      + max(0, $workshopLevel - 1) * $this->cfg->roboticsExplorationCellsPerLevel;
        $cellsToOpen  = (int) floor($hoursSpent * $cellsPerHour);

        // 7) Проверяем, заданы ли стартовые координаты в task_settings (ожидается формат "X,Y")
        $startCoords = null;
        if (!empty($task['task_settings'])) {
            $settingsArr = json_decode($task['task_settings'], true);
            if (!empty($settingsArr) && isset($settingsArr['coordinates'])) {
                $xVal = (int) $settingsArr['coordinates']['x'];
                $yVal = (int) $settingsArr['coordinates']['y'];
                $startCoords = ['x' => $xVal, 'y' => $yVal];
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

        // 11) Отправляем сообщение в Telegram (lazy через BaseTaskHandler)
        $this->safeSendMessage($chatId, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 12) Запускаем PvP-обнаружение
        $this->playerDetectionService->detectNearbyPlayers($character['id']);
    }

    /**
     * Алгоритм "змейки" (старый вариант) с движением по горизонтали.
     */
    private function calculateNewCellsSnake(
        array|\App\Entities\CharacterEntity $character,
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
     * @param array|\App\Entities\CharacterEntity $character Данные персонажа.
     * @param int $cellsToOpen Количество ячеек для открытия.
     * @param MapModel $mapModel Модель карты.
     * @param ExploredCellsModel $exploredCellsModel Модель изученных ячеек.
     * @param array $task Данные задачи (для telegram_user_id).
     * @param array|null $startCoords Стартовые координаты в виде ['x' => ..., 'y' => ...].
     * @return array Массив найденных ячеек.
     */
    private function calculateNewCellsCircle(
        array|\App\Entities\CharacterEntity $character,
        int $cellsToOpen,
        MapModel $mapModel,
        ExploredCellsModel $exploredCellsModel,
        array $task,
        ?array $startCoords = null
    ): array {
        $newCells = [];

        // 1) Центр
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

        // Учитываем, что в БД карта от 0 до 999
        $centerX = max(0, min(999, $centerX));
        $centerY = max(0, min(999, $centerY));

        $visited = [];
        $characterId    = $character['id'];
        $telegramUserId = $task['telegram_user_id'];

        $countOpened = 0;
        $r = 0;

        while ($countOpened < $cellsToOpen) {
            // Допустим, если r превысит 999 — хватит
            if ($r > 999) {
                break;
            }

            // Границы обхода по x,y
            $xMin = max(0, $centerX - $r);
            $xMax = min(999, $centerX + $r);
            $yMin = max(0, $centerY - $r);
            $yMax = max(0, $centerY + $r);

            for ($x = $xMin; $x <= $xMax; $x++) {
                for ($y = $yMin; $y <= $yMax; $y++) {

                    // Проверка, не добавляли ли уже
                    $key = "{$x}_{$y}";
                    if (isset($visited[$key])) {
                        continue;
                    }

                    // Проверяем, действительно ли (x, y) в круге радиуса r
                    // (x-centerX)^2 + (y-centerY)^2 <= r^2
                    $dx = $x - $centerX;
                    $dy = $y - $centerY;
                    if (($dx * $dx + $dy * $dy) <= ($r * $r)) {
                        $visited[$key] = true;  // помечаем как посещённую

                        // Ищем клетку в БД map
                        $cell = $mapModel
                            ->where('coordinate_x', $x)
                            ->where('coordinate_y', $y)
                            ->first();
                        if (!$cell) {
                            continue;
                        }

                        // Сохраняем в массив
                        $biomeModel = new BiomeModel();
                        $biome = $biomeModel->find($cell['biome_id']);
                        $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                        $newCells[] = [
                            'cell_number' => $cell['cell_number'],
                            'coordinates' => "X={$x}, Y={$y}",
                            'biome'       => $biomeName,
                            'biome_id'    => $cell['biome_id'],
                        ];

                        // Пишем в explored_cells
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

                        $countOpened++;
                        if ($countOpened >= $cellsToOpen) { // Проверка лимита здесь
                            return $newCells; // Выход, если лимит достигнут
                        }
                    }
                }
            }

            $r++;
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
