<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
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
use App\Services\Player\RobotService;
use App\Models\BuildingModel;
use App\Models\CraftedItemsModel;

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
#[HandlerKey(
    key: 'complete_robot_exploration',
    displayName: 'Завершение разведки роботом',
    description: 'Робот-разведчик: открывает соседние ячейки карты вокруг базы или заданного центра (змейка/круг).',
)]
class CompleteRobotExplorationHandler extends BaseTaskHandler
{
    private PlayerDetectionService $playerDetectionService;
    private RobotService $robotService;

    public function __construct(?RobotService $robotService = null)
    {
        // V18 (ADR-049): cells_base/per_level + tier-множитель читаются через
        // RobotService (GameSettings, ADR-024) вместо Config\GameBalance.
        $this->robotService = $robotService ?? new RobotService();
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

        // 5) Уровень Мастерской робототехники.
        // V18 (ADR-049) FIX: раньше искали building_id=999 (placeholder) → уровень
        // всегда = 1, а per-level cells-rate был МЁРТВ (на L10 робот открывал ~3000
        // клеток вместо задуманных ~8400). Резолвим по name_en (как gathering-хендлер).
        $workshopLevel      = 1;
        $workshopRow        = (new BuildingModel())->where('name_en', 'RoboticsWorkshop')->first();
        $workshopBuildingId = is_array($workshopRow) && isset($workshopRow['id']) && is_numeric($workshopRow['id'])
            ? (int) $workshopRow['id']
            : 9;
        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $workshopBuildingId)
            ->first();
        if ($roboticsWorkshop) {
            $lvlRaw        = $roboticsWorkshop['level'] ?? 1;
            $workshopLevel = is_numeric($lvlRaw) ? (int) $lvlRaw : 1;
        }

        // 6) Кол-во ячеек/час: base + (level-1)×perLevel (V18: через RobotService/GameSettings).
        $cellsPerHour = $this->robotService->explorationCellsBase()
                      + max(0, $workshopLevel - 1) * $this->robotService->explorationCellsPerLevel();
        $cellsToOpen  = (int) floor($hoursSpent * $cellsPerHour);

        // V18 (ADR-049): tier-множитель по запущенному роботу (T2 Разведчик → больше клеток).
        $robotNameEn = $this->resolveRobotNameEn($task);
        $cellsToOpen = (int) floor($cellsToOpen * $this->robotService->explorationCellsMultiplierFor($robotNameEn));

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
                    ['text' => '🗺️ Поход', 'callback_data' => 'march'],
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

        // v0.51.27 perf: pre-load усі biomes (~9 rows) як id→row map.
        // Раніше: `new BiomeModel()` + `find()` per-cell всередині triple-loop.
        $biomeModel = new BiomeModel();
        $biomesById = [];
        foreach ($biomeModel->findAll() as $b) {
            $biomesById[(int) $b['id']] = $b;
        }

        // v0.51.27 perf: pre-load existing explored cells map для character (set lookup).
        // Раніше: per-cell `WHERE character_id AND map_cell_id` query.
        $existingCells = $exploredCellsModel
            ->where('character_id', $characterId)
            ->findAll();
        $exploredSet = [];
        foreach ($existingCells as $ec) {
            $exploredSet[(int) $ec['map_cell_id']] = true;
        }

        while ($countOpened < $cellsToOpen) {
            // Допустим, если r превысит 999 — хватит
            if ($r > 999) {
                break;
            }

            // Границы обхода по x,y
            $xMin = max(0, $centerX - $r);
            $xMax = min(999, $centerX + $r);
            $yMin = max(0, $centerY - $r);
            $yMax = max(999, $centerY + $r);
            $yMax = min(999, $yMax);

            // v0.51.27 perf: 1 SQL bounding-box fetch на ring замість per-cell queries.
            $rows = $mapModel
                ->where('coordinate_x >=', $xMin)
                ->where('coordinate_x <=', $xMax)
                ->where('coordinate_y >=', $yMin)
                ->where('coordinate_y <=', $yMax)
                ->findAll();
            $cellsByXY = [];
            foreach ($rows as $row) {
                $cellsByXY[((int) $row['coordinate_x']) . '_' . ((int) $row['coordinate_y'])] = $row;
            }

            for ($x = $xMin; $x <= $xMax; $x++) {
                for ($y = $yMin; $y <= $yMax; $y++) {

                    $key = "{$x}_{$y}";
                    if (isset($visited[$key])) {
                        continue;
                    }

                    // Перевіряємо, чи (x, y) у крузі радіусу r
                    $dx = $x - $centerX;
                    $dy = $y - $centerY;
                    if (($dx * $dx + $dy * $dy) > ($r * $r)) {
                        continue;
                    }
                    $visited[$key] = true;

                    $cell = $cellsByXY[$key] ?? null;
                    if (!$cell) {
                        continue;
                    }

                    $bId   = (int) $cell['biome_id'];
                    $biome = $biomesById[$bId] ?? null;
                    $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                    $newCells[] = [
                        'cell_number' => $cell['cell_number'],
                        'coordinates' => "X={$x}, Y={$y}",
                        'biome'       => $biomeName,
                        'biome_id'    => $bId,
                    ];

                    // Insert у explored_cells якщо ще не існує (preloaded set).
                    $cellNum = (int) $cell['cell_number'];
                    if (!isset($exploredSet[$cellNum])) {
                        $exploredCellsModel->insert([
                            'character_id'     => $characterId,
                            'telegram_user_id' => $telegramUserId,
                            'map_cell_id'      => $cellNum,
                            'biome_id'         => $bId,
                            'character_level'  => $character['level'],
                        ]);
                        $exploredSet[$cellNum] = true;
                    }

                    $countOpened++;
                    if ($countOpened >= $cellsToOpen) {
                        return $newCells;
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

    /**
     * V18 (ADR-049): name_eng запущенного робота из task_settings.crafted_item_id
     * (старт-экшены/команда его пишут). null → старый task без поля / робот не найден
     * → RobotService вернёт нейтральный множитель 1.0 (0 регрессии).
     *
     * @param array<string,mixed> $task
     */
    private function resolveRobotNameEn(array $task): ?string
    {
        $raw = $task['task_settings'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['crafted_item_id']) || !is_numeric($decoded['crafted_item_id'])) {
            return null;
        }
        $row  = (new CraftedItemsModel())->find((int) $decoded['crafted_item_id']);
        $name = is_array($row) && isset($row['name_eng']) ? $row['name_eng'] : null;
        return is_string($name) ? $name : null;
    }
}
