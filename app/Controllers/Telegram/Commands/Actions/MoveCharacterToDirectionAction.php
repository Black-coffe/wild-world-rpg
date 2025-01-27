<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerDetectionService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Универсальный класс для перемещения персонажа в одно из 8 направлений
 * через колбэк "move_dir_{direction}".
 *
 * Учитываются все основные проверки:
 *  1) Активные блокирующие задачи (например, исследование, добыча),
 *  2) Предварительный расчёт списания здоровья/усталости (чтобы не уходить в минус),
 *  3) Проверка "исследована ли ячейка?",
 *  4) Разрешаем нескольким игрокам находиться в одной ячейке (для PvP),
 *  5) Обновляем статы и в конце вызываем detectNearbyPlayers(...) — оно само покажет,
 *     если рядом или в этой же клетке обнаружены другие игроки (с вопросом "атаковать/убежать?").
 */
class MoveCharacterToDirectionAction
{
    protected $callbackQuery;

    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;
    protected $playerDetectionService;

    // Словарь направлений: ключ => [dx, dy]
    protected $directions = [
        'north'      => [ 0, -1 ],
        'south'      => [ 0,  1 ],
        'west'       => [-1,  0 ],
        'east'       => [ 1,  0 ],
        'northwest'  => [-1, -1 ],
        'northeast'  => [ 1, -1 ],
        'southwest'  => [-1,  1 ],
        'southeast'  => [ 1,  1 ],
    ];

    // Базовые «расходы» на перемещение
    protected $baseHealthCost = 5;   // Сколько здоровья списываем
    protected $baseTiredCost  = 10;  // Сколько усталости списываем

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;

        $this->characterModel        = new CharacterModel();
        $this->characterTaskModel    = new CharacterTaskModel();
        $this->taskModel             = new TaskModel();
        $this->mapModel              = new MapModel();
        $this->telegramUserModel     = new TelegramUserModel();
        $this->exploredCellsModel    = new ExploredCellsModel();
        $this->biomeModel            = new BiomeModel();
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle(): ServerResponse
    {
        // 1. Общие данные
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $from           = $this->callbackQuery->getFrom();
        $telegramUserId = $from->getId();

        $callbackData = $this->callbackQuery->getData(); // например, "move_dir_north"

        // 2. Извлекаем направление из callback_data
        $direction = str_replace('move_dir_', '', $callbackData);

        if (!isset($this->directions[$direction])) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ошибка: неизвестное направление {$direction}."
            ]);
        }

        // 3. Ищем пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.'
            ]);
        }

        // 4. Ищем персонажа
        $character = $this->characterModel
            ->where('telegram_user_id', $user['id'])
            ->first();

        if (!$character || !$character['cell_number']) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или не имеет локации.'
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // === 5. Проверка активных блокирующих задач (исследование, добыча) ===
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        $blockingTasks = [];
        foreach ($activeTasks as $taskRow) {
            $taskInfo = $this->taskModel->find($taskRow['task_id']);
            if (!$taskInfo) {
                continue;
            }
            // Критерий блокировки: parallel_execution_allowed == 0
            if ($taskInfo['parallel_execution_allowed'] == 0) {
                $blockingTasks[] = $taskInfo['name_rus'] ?? $taskInfo['name'];
            }
        }

        if (!empty($blockingTasks)) {
            $taskName = $blockingTasks[0];
            $text = "🚫 *Невозможно переместиться!* У вас идёт задача: "
                . "\n*{$taskName}*\n"
                . "Сначала завершите или дождитесь окончания.";
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
                'parse_mode' => 'Markdown',
            ]);
        }

        // === 6. Текущая ячейка
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Текущая локация не найдена.'
            ]);
        }

        // === 7. Координаты целевой ячейки
        [$dx, $dy] = $this->directions[$direction];
        $newX = $currentCell['coordinate_x'] + $dx;
        $newY = $currentCell['coordinate_y'] + $dy;

        $targetCell = $this->mapModel
            ->where('coordinate_x', $newX)
            ->where('coordinate_y', $newY)
            ->first();

        if (!$targetCell) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нет ячейки по направлению '{$direction}'."
            ]);
        }

        // === 8. Проверяем, исследована ли эта ячейка
        $explored = $this->exploredCellsModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $targetCell['id'])
            ->first();

        if (!$explored) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions'],
                        ['text' => '🗺️ Изучить',    'callback_data' => 'explore'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => 'Локация не исследована! Невозможно переехать.',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // === 9. Предварительно считаем списание статов
        $healthCost = $this->baseHealthCost;
        $tiredCost  = $this->baseTiredCost;

        // Пример: если биом опасен (danger_level >=8), чуть больше урон
        $biome = $this->biomeModel->find($targetCell['biome_id']);
        if ($biome && $biome['danger_level'] >= 8) {
            $healthCost += 2;
        }

        $futureHealth = $character['health'] - $healthCost;
        $futureTired  = $character['tired']  - $tiredCost;

        // Если уходим в минус — блокируем
        if ($futureHealth < 0 || $futureTired < 0) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            $text = "Недостаточно ресурсов на перемещение:\n"
                . "Здоровье после перехода: {$futureHealth}\n"
                . "Усталость после перехода: {$futureTired}\n\n"
                . "Перемещение отменено.";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        // === 10. Разрешаем PvP, поэтому не блокируем, если есть другие игроки
        //     (Старая логика "if (!empty($playersInTargetCell)) { return ... }" УДАЛЕНА)
        //     Просто пропускаем. После перемещения PlayerDetectionService сообщит, если рядом/вместе есть игроки.

        // === 11. Обновляем персонажа
        $addedStrength   = 0.1;
        $addedExperience = 0.05;

        $this->characterModel->update($character['id'], [
            'cell_number' => $targetCell['cell_number'],
            'biome_id'    => $targetCell['biome_id'],
            'health'      => $futureHealth,
            'tired'       => $futureTired,
            'strength'    => $character['strength'] + $addedStrength,
            'experience'  => $character['experience'] + $addedExperience,
        ]);

        // === 12. Формируем итоговое сообщение
        $biomeName       = $biome['name'] ?? '???';
        $dangerLevel     = $biome['danger_level'] ?? '?';
        $survivalDiff    = $biome['survival_difficulty'] ?? '?';

        $text = "🚚 *Вы двинулись на: *`{$direction}`\n\n"
            . "🔲 *Новая ячейка:* #{$targetCell['cell_number']}\n"
            . "🧭 Координаты: X = {$targetCell['coordinate_x']}, Y = {$targetCell['coordinate_y']}\n\n"
            . "🌿 Биом: {$biomeName}\n"
            . "⚠️ Уровень опасности: {$dangerLevel}\n"
            . "💪 Сложность выживания: {$survivalDiff}\n\n"
            . "Здоровье: *{$futureHealth}%*, Усталость: *{$futureTired}%*\n"
            . "Сила: *" . round($character['strength'] + $addedStrength, 2) . "*\n"
            . "Опыт: *" . round($character['experience'] + $addedExperience, 2) . "*";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия', 'callback_data' => 'characterActions'],
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory']
                ],
                [
                    ['text' => '🎉 События',   'callback_data' => 'events']
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/map-lines-coordinates.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $response = Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // === 13. По окончании вызываем detectNearbyPlayers,
        //         чтобы сервис (PlayerDetectionService) показал
        //         "Обнаружен игрок <...> хотите атаковать/убежать?"
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return $response;
    }
}
