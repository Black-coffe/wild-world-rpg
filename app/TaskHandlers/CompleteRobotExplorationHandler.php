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
 * Класс-обработчик завершения задачи, когда робот (или иная логика) завершил исследование.
 *
 * Если в task_settings (character_tasks) лежат координаты в формате "X,Y",
 * мы используем их как стартовую позицию змейки.
 * Иначе берём cell_number персонажа.
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
     * @param array $task — запись из character_tasks (включая [id, character_id, start_time, end_time, task_settings...])
     */
    public function handle($task)
    {
        // 1) Подключаем модели
        $characterTaskModel     = new CharacterTaskModel();
        $characterModel         = new CharacterModel();
        $characterBuildingModel = new CharacterBuildingModel();
        $mapModel               = new MapModel();
        $exploredCellsModel     = new ExploredCellsModel();
        $biomeModel             = new BiomeModel();
        $biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $worldObjectModel       = new WorldObjectModel();
        $telegramUserModel      = new TelegramUserModel();

        // 2) Ставим задаче статус = 'completed'
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 3) Получаем персонажа и чат
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

        // 4) Сколько часов прошло
        $startTime  = strtotime($task['start_time']);
        $endTime    = strtotime($task['end_time']);
        $hoursSpent = max(0, ($endTime - $startTime) / 3600);

        // 5) Уровень мастерской (опционально)
        $workshopLevel = 1;
        // Если у вас реальный ID RoboticsWorkshop, подставьте. Ниже - "999"
        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', 999)
            ->first();
        if ($roboticsWorkshop) {
            $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        }

        // 6) Сколько ячеек хотим открыть
        $cellsPerHour = 50 + max(0, $workshopLevel - 1) * 10;
        $cellsToOpen  = (int) floor($hoursSpent * $cellsPerHour);

        // 7) Ищем, есть ли координаты запуска в task_settings
        $startCoords = null;
        if (!empty($task['task_settings'])) {
            // Предполагаем формат "X,Y"
            $pattern = '/^(\d+),(\d+)$/';
            if (preg_match($pattern, $task['task_settings'], $m)) {
                $xVal = (int) $m[1];
                $yVal = (int) $m[2];
                // Проверим допустимость [1..1000]
                if ($xVal >= 1 && $xVal <= 1000 && $yVal >= 1 && $yVal <= 1000) {
                    // Всё ок
                    $startCoords = ['x' => $xVal, 'y' => $yVal];
                }
            }
        }

        // 8) Запускаем "змейку"
        //    Если startCoords НЕ null, используем их, иначе используем cell_number персонажа
        $newCells = $this->calculateNewCellsSnake(
            $character,
            $cellsToOpen,
            $mapModel,
            $biomeModel,
            $exploredCellsModel,
            $task,
            $startCoords
        );

        // 9) Формируем сообщение
        $text = $this->formatExplorationResultMessage($newCells, $hoursSpent, $cellsToOpen);

        // 10) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🚜 Переехать',  'callback_data' => 'move'],
                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                ],
            ]
        ];

        // 11) Отправляем сообщение
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 12) PvP обнаружение
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return true;
    }

    /**
     * "Змейка" с движением вверх (y--):
     *
     * Если переданы $startCoords (x,y), используем их как начальную точку.
     * Иначе - смотрим cell_number у персонажа и берём оттуда (x,y).
     */
    private function calculateNewCellsSnake(
        array $character,
        int   $cellsToOpen,
        MapModel $mapModel,
        BiomeModel $biomeModel,
        ExploredCellsModel $exploredCellsModel,
        array $task,
        ?array $startCoords = null
    ): array {
        $newCells = [];

        // Если есть $startCoords - используем их, иначе ищем cell_number
        if ($startCoords !== null) {
            $x = $startCoords['x'];
            $y = $startCoords['y'];
        } else {
            // Стартовые координаты берём из карты, где cell_number = character['cell_number']
            $currentCell = $mapModel
                ->where('cell_number', $character['cell_number'])
                ->first();
            if (!$currentCell) {
                return $newCells;
            }
            $x = $currentCell['coordinate_x'];
            $y = $currentCell['coordinate_y'];
        }

        // Флаг - идём вправо/влево
        $goingRight = true;
        $openedCount = 0;

        // Для записи в explored_cells
        $characterId    = $character['id'];
        $telegramUserId = $task['telegram_user_id'];

        while ($openedCount < $cellsToOpen) {
            if ($y < 0) {
                // Достигли верхней границы
                break;
            }

            // Если дошли до (x=1, y=1) => прыгаем на (x=1, y=1000)
            if ($x == 1 && $y == 1) {
                $y = 1000;
            }

            // Проверяем ячейку
            $cell = $mapModel
                ->where('coordinate_x', $x)
                ->where('coordinate_y', $y)
                ->first();

            if ($cell) {
                // Проверяем, не открыта ли
                $alreadyOpened = $exploredCellsModel
                    ->where('character_id', $characterId)
                    ->where('map_cell_id', $cell['cell_number'])
                    ->first();

                if (!$alreadyOpened) {
                    // Открываем
                    $biome     = $biomeModel->find($cell['biome_id']);
                    $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                    $newCells[] = [
                        'cell_number' => $cell['cell_number'],
                        'coordinates' => "X={$x}, Y={$y}",
                        'biome'       => $biomeName,
                        'biome_id'    => $cell['biome_id'],
                    ];

                    // Записываем
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
            // Двигаемся дальше по горизонтали
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
     * Формируем финальное сообщение (сколько планировали открыть, сколько фактически, какие биомы).
     */
    private function formatExplorationResultMessage(
        array $newCells,
        float $hoursSpent,
        int   $cellsToOpen
    ): string {
        $countOpened = count($newCells);

        // Собираем список биомов
        $biomeNames   = array_column($newCells, 'biome');
        $uniqueBiomes = array_unique($biomeNames);

        // Преобразуем 4.183333 в "4 ч 11 мин"
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
