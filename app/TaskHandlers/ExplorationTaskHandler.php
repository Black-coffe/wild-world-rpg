<?php

namespace App\TaskHandlers;

use App\Models\BiomeModel;
use App\Models\BiomeWorldObjectMapModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Models\WorldObjectModel;
use App\Services\Player\PlayerDetectionService;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Сервис обнаружения игроков

class ExplorationTaskHandler extends Controller
{
    private $telegram;
    private $playerDetectionService;

    public function __construct()
    {
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }

        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle($task)
    {
        // 1) Обновляем статус задачи на 'completed'
        $characterTaskModel = new CharacterTaskModel();
        $characterTaskModel->update($task['id'], ['status' => 'completed']);

        // 2) Получаем персонажа и делаем базовые +0.01/+0.03/+0.01/+0.02
        $characterModel = new CharacterModel();
        $character      = $characterModel->find($task['character_id']);
        if (!$character) {
            log_message('error', 'Персонаж не найден при закрытии задачи ExplorationTask.');
            return;
        }

        $characterModel->update($character['id'], [
            'experience' => $character['experience'] + 0.01,
            'strength'   => $character['strength']   + 0.03,
            'agility'    => $character['agility']    + 0.01,
            'intellect'  => $character['intellect']  + 0.02,
        ]);

        // 3) Ищем текущую локацию + формируем список ячеек
        $mapModel  = new MapModel();
        $biomeModel= new BiomeModel();
        $userModel = new TelegramUserModel();
        $chat_id   = $userModel->where('id', $task['telegram_user_id'])->first()['telegram_id'];

        $currentCell = $mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            log_message('error', 'Текущая ячейка персонажа не найдена.');
            return;
        }

        // 4) Получаем ячейки вокруг (учитывая уровень)
        $surroundingCells = $this->getSurroundingCellsWithLevels($currentCell, $mapModel, $biomeModel, $character);

        // 5) Учитываем здоровье и усталость, снижаем количество (до 10%)
        //    по формуле: каждые 10% потери (здоровье или выносливость) = -1% ячеек
        $surroundingCells = $this->applyHealthAndTirednessReduction($surroundingCells, $character);

        // 6) Обнаружение объектов
        $biomeWorldObjectMapModel = new BiomeWorldObjectMapModel();
        $worldObjectModel         = new WorldObjectModel();
        $discoveryService         = new \App\Services\World\ObjectDiscoveryService(
            $biomeWorldObjectMapModel,
            $worldObjectModel
        );

        /*
        // СТАРАЯ логика: вызывала handlers для всех открытых ячеек
        $discoveryService->discoverObjects($surroundingCells, $character);
        */

        // НОВАЯ логика: вызывала handlers ТОЛЬКО в той ячейке, где герой
        $discoveryService->discoverObjectsAtPlayerPosition($character);

        // 7) Запись информации об изученных ячейках
        $exploredCellsModel = new ExploredCellsModel();
        foreach ($surroundingCells as $cell) {
            // Проверяем, есть ли запись
            $found = $exploredCellsModel
                ->where('character_id', $character['id'])
                ->where('map_cell_id', $cell['cell_number'])
                ->countAllResults();

            // Если записи нет, вставляем
            if ($found == 0) {
                $exploredCellsModel->insert([
                    'character_id'     => $character['id'],
                    'telegram_user_id' => $task['telegram_user_id'],
                    'map_cell_id'      => $cell['cell_number'],
                    'biome_id'         => $cell['biome_id'],
                    'character_level'  => $character['level'],
                ]);
            }
        }

        // 8) Detected nearby players
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        // 9) Формируем сообщение
        $text = $this->formatExplorationResultMessage($surroundingCells);

        // 10) Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '🚜 Переехать',   'callback_data' => 'move'],
                ],
            ]
        ];

        // 11) Отправляем
        return Request::sendMessage([
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Формируем список всех ячеек вокруг, основываясь на уровне персонажа:
     * 1..10  -> радиус 1
     * 11..99 -> радиус 2
     * 100..350 -> радиус 3
     * 351..700 -> радиус 4
     * 701..1000 -> радиус 5
     */
    private function getSurroundingCellsWithLevels($currentCell, MapModel $mapModel, BiomeModel $biomeModel, array $character)
    {
        $x     = $currentCell['coordinate_x'];
        $y     = $currentCell['coordinate_y'];
        $level = (int) $character['level'];

        // Определяем радиус (кол-во колец)
        $ring = $this->getRingByLevel($level);

        $result = [];
        for ($dx = -$ring; $dx <= $ring; $dx++) {
            for ($dy = -$ring; $dy <= $ring; $dy++) {

                // Пропускаем (0,0)
                if ($dx === 0 && $dy === 0) {
                    continue;
                }

                // Координаты
                $nx = $x + $dx;
                $ny = $y + $dy;

                // Пределы карты (допустим 1..1000)
                if ($nx < 1 || $nx > 1000 || $ny < 1 || $ny > 1000) {
                    continue;
                }

                $cell = $mapModel
                    ->where('coordinate_x', $nx)
                    ->where('coordinate_y', $ny)
                    ->first();

                if ($cell) {
                    $biome     = $biomeModel->find($cell['biome_id']);
                    $biomeName = $biome ? $biome['name'] : 'Неизвестный биом';

                    $result[] = [
                        'cell_number' => $cell['cell_number'],
                        'coordinates' => "X={$nx}, Y={$ny}",
                        'biome'       => $biomeName,
                        'biome_id'    => $cell['biome_id'],
                        'map_id'      => $cell['id'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Вспомогательный метод: возвращает кол-во «колец» (радиус) на основе уровня.
     */
    private function getRingByLevel(int $level): int
    {
        return match (true) {
            $level <= 10    => 1,
            $level <= 99    => 2,
            $level <= 350   => 3,
            $level <= 700   => 4,
            default         => 5, // 701..1000
        };
    }

    /**
     * Учитываем здоровье (health) и выносливость (tired).
     * - Макс здоровье / выносливость = 100
     * - Каждые -10% (здоровье ИЛИ выносливость) => -1% ячеек.
     *   Суммируем проценты потерь, но максимум снижения 10%.
     *
     * Пример:
     *   health= 80 => потеря 20% => 2%
     *   tired = 50 => потеря 50% => 5%
     *   итого 7% => -7% ячеек.
     */
    private function applyHealthAndTirednessReduction(array $cells, array $character): array
    {
        $maxHealth = 100.0;
        $maxTired  = 100.0;

        // Процент потерь (здоровье)
        $healthLostPercent = 0.0;
        if ($character['health'] < $maxHealth) {
            $healthLostPercent = ($maxHealth - $character['health']); // 100 - (health)
        }
        // Процент потерь (выносливость)
        $tiredLostPercent = 0.0;
        if ($character['tired'] < $maxTired) {
            $tiredLostPercent = ($maxTired - $character['tired']);
        }

        // healthLostPercent=20 => это 20% => 2% от ячеек
        // tiredLostPercent=50 => 50% => 5% от ячеек
        // итого 7%
        // ограничим максимум 10%
        $summedPercent = ($healthLostPercent / 10) + ($tiredLostPercent / 10);
        if ($summedPercent > 10) {
            $summedPercent = 10; // max -10% от ячеек
        }

        // Считаем кол-во ячеек, вычитаем этот процент
        $countCells  = count($cells);
        if ($countCells === 0) {
            return $cells; // нечего урезать
        }

        // Сколько нужно отбросить
        // 7% => 0.07 * count
        // 10% => 0.10 * count
        $dropCount = floor($countCells * ($summedPercent / 100.0));

        // Если dropCount=0, не трогаем
        if ($dropCount <= 0) {
            return $cells;
        }

        // Удаляем случайные dropCount ячеек
        // Перемешиваем массив
        shuffle($cells);

        // Отбрасываем нужное кол-во в конце
        // (либо в начале, не принципиально)
        $cells = array_slice($cells, 0, $countCells - $dropCount);

        return $cells;
    }

    /**
     * Формируем итоговое сообщение в игровом стиле:
     * 1) Сколько всего новых ячеек изучено.
     * 2) Подсчёт и вывод по биомам.
     */
    private function formatExplorationResultMessage(array $surroundingCells): string
    {
        $totalDiscovered = count($surroundingCells);

        // Если ничего не нашли
        if ($totalDiscovered === 0) {
            return "*Поздравляем с возвращением!* 🎉\n\n"
                . "Похоже, усталость и раны не дали разгуляться. 😓\n"
                . "_Ни одной новой локации не открыто._\n\n"
                . "Но не расстраивайся — даже опыт ошибки помогает расти!\n\n"
                . "📍 *Решай, останешься тут или рванёшь дальше.*\n"
                . "💡 *Параметры всё же чуток улучшились — молодец!*";
        }

        // Подсчёт ячеек по биомам
        $biomeCounts = [];
        foreach ($surroundingCells as $cellInfo) {
            $biomeName = $cellInfo['biome'] ?? 'Неизвестный биом';
            if (!isset($biomeCounts[$biomeName])) {
                $biomeCounts[$biomeName] = 0;
            }
            $biomeCounts[$biomeName]++;
        }

        // Начало сообщения
        $message  = "*Отличная работа!* 🙌\n\n";
        $message .= "Ты вернулся из разведки с трофеями знаний! 🗺\n\n"
            . "За это время тебе удалось открыть: *{$totalDiscovered}* новых ячеек.\n\n"
            . "Среди них встречаются:\n";

        // Подробности по биомам
        foreach ($biomeCounts as $biomeName => $count) {
            $message .= "• *{$biomeName}*: {$count} шт.\n";
        }

        // Завершающая часть
        $message .= "\n"
            . "🔎 *Теперь решай: остаёмся здесь или покоряем новые земли?*\n"
            . "💡 *Характеристики тоже подросли — так держать!*";

        return $message;
    }
}
