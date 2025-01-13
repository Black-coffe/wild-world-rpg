<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Models\ExploredCellsModel;
use App\Models\BiomeModel;

class MoveCharacterAction
{
    protected $callbackQuery;
    protected $characterModel;
    protected $mapModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;

    // Урезанный радиус: теперь только 3 клетки «вглубь» вокруг персонажа
    protected $visionRadius = 3;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery      = $callbackQuery;
        $this->characterModel     = new CharacterModel();
        $this->mapModel           = new MapModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel         = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // 1. Проверяем пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных.'
            ]);
        }

        // 2. Проверяем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или не имеет локации.'
            ]);
        }

        // 3. Проверка здоровья и усталости
        if ($character['health'] <= 5 || $character['tired'] <= 10) {
            $text = "🚑 *Ваш персонаж не выдержит больше физических нагрузок.*\n\n"
                . "*Необходимо восстановить здоровье или выносливость*\n\n"
                . "*💖 здоровье: {$character['health']} %*\n"
                . "*🥱 выносливость: {$character['tired']} %*\n\n"
                . "*Отлежитесь или используйте аптечку* 👇\n\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                    ],
                ]
            ];

            $imagePath = base_url('uploads/telegram/character_exhausted_need_treatment.png');
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

            return Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // 4. Проверяем параллельные задачи
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        // Ищем непараллельные задачи
        $nonParallelTasks = [];
        foreach ($activeTasks as $task) {
            $taskDetails = $this->taskModel->find($task['task_id']);
            if ($taskDetails && $taskDetails['parallel_execution_allowed'] == 0) {
                $nonParallelTasks[] = $task;
            }
        }

        if (!empty($nonParallelTasks)) {
            // Если есть задача, которая не допускает параллельного выполнения
            $task        = $nonParallelTasks[0];
            $taskDetails = $this->taskModel->find($task['task_id']);

            $endTime     = new \DateTime($task['end_time']);
            $now         = new \DateTime();
            $timeLeft    = $now > $endTime ? 0 : $now->diff($endTime);
            $minutesLeft = $now > $endTime ? 0 : ($timeLeft->days * 24 * 60 + $timeLeft->h * 60 + $timeLeft->i);
            $timeLeftText = $now > $endTime ? "00" : $minutesLeft;

            $text = "*Ой! Не получается начать переезд!* 😥\n\n"
                . "*Вы заняты выполнением другой задачи:* \n\n"
                . "👉 *{$taskDetails['name_rus']}* 👈\n"
                . "⌛️ До конца ещё: *{$timeLeftText}* минут!\n\n"
                . "**😔 Пожалуйста, завершите текущую задачу или дождитесь окончания, прежде чем начинать новую.**\n\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                    ],
                ]
            ];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ]);
        }

        // 5. Текущая локация
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Локация персонажа не найдена.'
            ]);
        }

        // 6. Считаем количество ячеек, исследованных в радиусе (урезан до 3)
        $totalExploredInRadius = $this->countExploredCellsInRadius(
            $currentCell['coordinate_x'],
            $currentCell['coordinate_y'],
            $character['id'],
            $this->visionRadius
        );

        // 7. Информация о соседних направлениях (соседние ячейки)
        $surroundingCellsInfo = $this->getSurroundingCellsInfo(
            $currentCell['coordinate_x'],
            $currentCell['coordinate_y'],
            $character['id']
        );

        // 8. Генерируем клавиатуру
        $keyboardButtons = $this->generateKeyboard($surroundingCellsInfo, $character['id']);

        // Если совсем нет кнопок
        if (empty($keyboardButtons)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Нет доступных направлений для переезда. Возможно, стоит исследовать окрестности?'
            ]);
        }

        $keyboard = ['inline_keyboard' => $keyboardButtons];

        // 9. Формируем текст
        $text = "👋 Привет, герой! 🙋‍♂️\n\n"
            . "🚜 Ты готов к переезду? 🏡\n\n"
            . "🗺️ Доступные направления: выбирай, куда двинуться.\n\n"
            . "👀 Радиус обзора: *{$totalExploredInRadius}* ячеек\n\n"
            . "🍀 Удачи в пути! 🍀";

        $imagePath = base_url('uploads/telegram/moves_to_another_territory.png');

        // Ответ на callback
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Итоговое сообщение
        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Генерируем кнопки с учётом количества ячеек (не более 10) в каждом направлении.
     */
    private function generateKeyboard(array $surroundingCellsInfo, int $characterId): array
    {
        $directions = [
            ['northwest' => '↖️ Северо-запад', 'northeast' => '↗️ Северо-восток'],
            ['north' => '⬆️ Север',           'south' => '⬇️ Юг'],
            ['west' => '⬅️ Запад',            'east' => '➡️ Восток'],
            ['southwest' => '↙️ Юго-запад',    'southeast' => '↘️ Юго-восток'],
        ];

        $keyboard = [];
        foreach ($directions as $pair) {
            $row = [];
            foreach ($pair as $dir => $label) {
                if (isset($surroundingCellsInfo[$dir])) {
                    // Сколько ещё исследованных клеток по направлению?
                    $countInDirection = $this->countExploredInSingleDirection(
                        $surroundingCellsInfo[$dir]['cell_number'],
                        $dir,
                        $characterId
                    );
                    $countLabel = min($countInDirection, 10);

                    $row[] = [
                        'text'          => "{$label} ({$countLabel})",
                        'callback_data' => "moveto{$dir}"
                    ];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }

        return $keyboard;
    }

    /**
     * Возвращаем словарь «направление -> данные» для соседних клеток.
     * Только если клетка исследована.
     */
    private function getSurroundingCellsInfo(int $x, int $y, int $characterId): array
    {
        $neighboringPositions = [
            'northwest' => [$x - 1, $y - 1],
            'north'     => [$x,     $y - 1],
            'northeast' => [$x + 1, $y - 1],
            'west'      => [$x - 1, $y],
            'east'      => [$x + 1, $y],
            'southwest' => [$x - 1, $y + 1],
            'south'     => [$x,     $y + 1],
            'southeast' => [$x + 1, $y + 1],
        ];

        $info = [];

        foreach ($neighboringPositions as $direction => [$nx, $ny]) {
            $cell = $this->mapModel
                ->where('coordinate_x', $nx)
                ->where('coordinate_y', $ny)
                ->first();
            if ($cell) {
                // Проверяем, исследована ли
                $explored = $this->exploredCellsModel
                    ->where('character_id', $characterId)
                    ->where('map_cell_id', $cell['id'])
                    ->first();
                if ($explored) {
                    // Можно добавить проверку биома
                    $biome = $this->biomeModel->find($cell['biome_id']);
                    $info[$direction] = [
                        'cell_number' => $cell['id'],
                        'biome_name'  => $biome ? $biome['name'] : 'Unknown',
                        'direction'   => $direction,
                    ];
                }
            }
        }
        return $info;
    }

    /**
     * Сколько исследованных ячеек есть в направлении $dir, начиная от $startCellId, до 10.
     */
    private function countExploredInSingleDirection(int $startCellId, string $dir, int $characterId): int
    {
        $directionVectors = [
            'north'     => [ 0, -1 ],
            'south'     => [ 0,  1 ],
            'west'      => [-1,  0 ],
            'east'      => [ 1,  0 ],
            'northwest' => [-1, -1 ],
            'northeast' => [ 1, -1 ],
            'southwest' => [-1,  1 ],
            'southeast' => [ 1,  1 ],
        ];

        if (!isset($directionVectors[$dir])) {
            return 0;
        }
        [$dx, $dy] = $directionVectors[$dir];

        $count       = 0;
        $startCell   = $this->mapModel->find($startCellId);
        if (!$startCell) {
            return 0;
        }

        $cx = $startCell['coordinate_x'];
        $cy = $startCell['coordinate_y'];

        // Делаем максимум 10 шагов
        for ($i = 0; $i < 10; $i++) {
            $cx += $dx;
            $cy += $dy;

            $cell = $this->mapModel
                ->where('coordinate_x', $cx)
                ->where('coordinate_y', $cy)
                ->first();
            if (!$cell) {
                break; // Клетки не существует
            }
            // Проверяем, исследовано ли
            $explored = $this->exploredCellsModel
                ->where('character_id', $characterId)
                ->where('map_cell_id', $cell['id'])
                ->first();
            if ($explored) {
                $count++;
            } else {
                // Если сразу упираемся в неисследованную, можно break
                // break;
            }
        }

        return $count;
    }

    /**
     * Считает общее число исследованных ячеек в квадрате ±$radius.
     */
    private function countExploredCellsInRadius(int $x, int $y, int $characterId, int $radius): int
    {
        $xMin = $x - $radius;
        $xMax = $x + $radius;
        $yMin = $y - $radius;
        $yMax = $y + $radius;

        // Ищем все клетки
        $cells = $this->mapModel
            ->where('coordinate_x >=', $xMin)
            ->where('coordinate_x <=', $xMax)
            ->where('coordinate_y >=', $yMin)
            ->where('coordinate_y <=', $yMax)
            ->findAll();

        $count = 0;
        foreach ($cells as $cell) {
            $explored = $this->exploredCellsModel
                ->where('character_id', $characterId)
                ->where('map_cell_id', $cell['id'])
                ->first();
            if ($explored) {
                $count++;
            }
        }
        return $count;
    }
}
