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
 * Логика "змейки" (snake) с движением ВВЕРХ (y уменьшается):
 *  - Начинаем с текущих координат (x,y) персонажа.
 *  - Движемся по горизонтали (вправо или влево) до границы (0..1000).
 *  - Когда упираемся в границу, переходим на строку выше (y--).
 *  - Меняем горизонтальное направление и продолжаем.
 *  - Если достигли y < 0, завершаем.
 *  - Если дошли до (x=1, y=1), "перепрыгиваем" на (x=1, y=1000) и продолжаем.
 *  - Открываем только те ячейки, которые в таблице map существуют (coordinate_x, coordinate_y) и которых нет в explored_cells.
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
     * @param array $task — запись из character_tasks
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
        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', 999) // Подставьте реальный ID RoboticsWorkshop, если нужно
            ->first();
        if ($roboticsWorkshop) {
            $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        }

        // 6) Рассчитываем, сколько ячеек хотим открыть
        //    Формула: 50 + 10*(workshopLevel - 1) за час
        $cellsPerHour = 50 + max(0, $workshopLevel - 1) * 10;
        $cellsToOpen  = (int) floor($hoursSpent * $cellsPerHour);

        // 7) Запускаем "змейку"
        $newCells = $this->calculateNewCellsSnake(
            $character,
            $cellsToOpen,
            $mapModel,
            $biomeModel,
            $exploredCellsModel,
            $task
        );

        // 8) Формируем сообщение
        $text = $this->formatExplorationResultMessage($newCells, $hoursSpent, $cellsToOpen);

        // 9) Кнопки
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

        // 10) Отправляем сообщение
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 11) PvP обнаружение
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return true;
    }

    /**
     * "Змейка" с движением вверх (y--):
     *  1) Начинаем с текущих координат (x, y) персонажа.
     *  2) Идём вправо (x++) пока x<=1000, затем y-- и идём влево (x--) пока x>=0, затем y-- и снова вправо...
     *  3) Если y<0 => выходим (достигли верха).
     *  4) Если в какой-то момент дошли до (x=1, y=1), то "перепрыгиваем" на (x=1, y=1000).
     *  5) Проверяем, есть ли ячейка в map, и не открыта ли она уже. Если всё ок — открываем.
     */
    private function calculateNewCellsSnake(
        array $character,
        int   $cellsToOpen,
        MapModel $mapModel,
        BiomeModel $biomeModel,
        ExploredCellsModel $exploredCellsModel,
        array $task
    ): array {
        $newCells = [];

        // Стартовые координаты
        $currentCell = $mapModel
            ->where('cell_number', $character['cell_number'])
            ->first();
        if (!$currentCell) {
            return $newCells;
        }

        $x = $currentCell['coordinate_x'];
        $y = $currentCell['coordinate_y'];

        // Идём ли вправо или влево?
        $goingRight = true;

        // Счётчик
        $openedCount = 0;

        // Для записи в explored_cells
        $characterId    = $character['id'];
        $telegramUserId = $task['telegram_user_id'];

        while ($openedCount < $cellsToOpen) {

            // 1) Если вышли за верхнюю границу
            if ($y < 0) {
                // Всё, достигли "верха"
                break;
            }

            // 2) Особое правило: если дошли до (x=1, y=1) => прыгаем на (x=1, y=1000)
            if ($x == 1 && $y == 1) {
                $y = 1000;
                // Считаем, что робот снова продолжает "змейку" с этой точки
                // goingRight не трогаем — пусть логика продолжается
            }

            // Проверяем текущую ячейку
            $cell = $mapModel
                ->where('coordinate_x', $x)
                ->where('coordinate_y', $y)
                ->first();
            if ($cell) {
                // Проверяем, уже открыта?
                $alreadyOpened = $exploredCellsModel
                    ->where('character_id', $characterId)
                    ->where('map_cell_id', $cell['cell_number'])
                    ->first();

                if (!$alreadyOpened) {
                    // Новая!
                    $biome     = $biomeModel->find($cell['biome_id']);
                    $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                    $newCells[] = [
                        'cell_number' => $cell['cell_number'],
                        'coordinates' => "X={$x}, Y={$y}",
                        'biome'       => $biomeName,
                        'biome_id'    => $cell['biome_id'],
                    ];

                    // Сохраняем в explored_cells
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
            // Если ячейки нет или она уже открыта — ничего не делаем, просто идём дальше

            // 3) Двигаемся по горизонтали
            if ($goingRight) {
                // Если x < 1000 -> x++
                if ($x < 1000) {
                    $x++;
                } else {
                    // Достигли правой границы -> поднимаемся (y--)
                    $y--;
                    // Меняем направление
                    $goingRight = false;
                }
            } else {
                // Двигаемся влево
                if ($x > 0) {
                    $x--;
                } else {
                    // Достигли левой границы
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

        // Допустим, $hoursSpent = 4.1833333333
        $wholeHours = floor($hoursSpent); // 4 (целая часть)
        $decimalPart = $hoursSpent - $wholeHours; // 0.1833333333
        $minutes = floor($decimalPart * 60);      // floor(0.1833... * 60) = 11

        // Формируем строку
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
