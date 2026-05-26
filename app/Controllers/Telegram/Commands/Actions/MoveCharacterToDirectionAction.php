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
use App\Services\World\TextMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Класс, обрабатывающий перемещение персонажа в одно из 8 направлений
 * через колбэк "move_dir_{direction}"
 *
 * 1) Проверяем ресурсы, исследования, блокировки
 * 2) Меняем координаты персонажа
 * 3) Формируем НОВЫЙ текст карты (в том же формате, что в MoveCharacterAction)
 * 4) Пытаемся "editMessageText", используя last_map_message_id
 * 5) Если неудачно (например, исходное сообщение удалено) — делаем sendMessage (fallback).
 */
class MoveCharacterToDirectionAction
{
    protected $callbackQuery;

    // Модели
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;

    protected $playerDetectionService;

    // Словарь направлений
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

    // Расходы на перемещение
    protected $baseHealthCost = 0.1;
    protected $baseTiredCost  = 3.35;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;

        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->taskModel              = new TaskModel();
        $this->mapModel               = new MapModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->exploredCellsModel     = new ExploredCellsModel();
        $this->biomeModel             = new BiomeModel();
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle(): ServerResponse
    {
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Снимаем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $callbackData = $this->callbackQuery->getData(); // "move_dir_north" etc
        $direction    = str_replace('move_dir_', '', $callbackData);

        // Валидируем направление
        if (!isset($this->directions[$direction])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Неизвестное направление: {$direction}."
            ]);
        }

        // Ищем telegram-пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден.'
            ]);
        }

        // Ищем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или нет cell_number.'
            ]);
        }

        // Проверка активного переезда
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверка других блокирующих задач
        $activeTasks = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('telegram_user_id', $user['id'])
            ->where('status', 'in_work')
            ->findAll();

        foreach ($activeTasks as $taskRow) {
            $taskInfo = $this->taskModel->find($taskRow['task_id']);
            if ($taskInfo && $taskInfo['parallel_execution_allowed'] == 0) {
                $taskName = $taskInfo['name_rus'] ?? $taskInfo['name'];
                $text = "🚫 Невозможно переместиться!\n"
                    . "У вас идёт задача: {$taskName}\n"
                    . "Сначала дождитесь окончания.";
                return Request::sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'Markdown'
                ]);
            }
        }

        // Текущая ячейка
        $currentCell = $this->mapModel
            ->where('cell_number', $character['cell_number'])
            ->first();
        if (!$currentCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Текущая локация не найдена!"
            ]);
        }

        // Координаты новой ячейки
        [$dx, $dy] = $this->directions[$direction];
        $newX = $currentCell['coordinate_x'] + $dx;
        $newY = $currentCell['coordinate_y'] + $dy;

        // Целевая ячейка
        $targetCell = $this->mapModel
            ->where('coordinate_x', $newX)
            ->where('coordinate_y', $newY)
            ->first();
        if (!$targetCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Нет ячейки по направлению {$direction}."
            ]);
        }

        // ADR-019 §2 (Step 1): гейт «клетка должна быть заранее исследована» снят —
        // движение И есть разведка. В любую in-bounds клетку шагнуть можно; факт
        // прихода раскрывает её + 8 соседей (туман войны radius-1) ниже, после move.
        // (Блокировки воды / чужого лагеря для одиночного шага — отдельный батч марша.)

        // Списываем здоровье/усталость
        $healthCost = $this->baseHealthCost;
        $tiredCost  = $this->baseTiredCost;

        // Если биом опасный
        $biome = $this->biomeModel->find($targetCell['biome_id']);
        if ($biome && $biome['danger_level'] >= 8) {
            $healthCost += 1.15;
        }

        $futureHealth = $character['health'] - $healthCost;
        $futureTired  = $character['tired']  - $tiredCost;
        if ($futureHealth < 0 || $futureTired < 0) {
            $text = "Недостаточно ресурсов на перемещение!\n"
                . "Здоровье после перехода: {$futureHealth}\n"
                . "Усталость после перехода: {$futureTired}";
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text
            ]);
        }

        // Обновляем персонажа
        $addedStrength   = 0.02;
        $addedExperience = 0.03;
        $this->characterModel->update($character['id'], [
            'cell_number' => $targetCell['cell_number'],
            'biome_id'    => $targetCell['biome_id'],
            'health'      => $futureHealth,
            'tired'       => $futureTired,
            'strength'    => $character['strength'] + $addedStrength,
            'experience'  => $character['experience'] + $addedExperience,
        ]);

        // Туман войны (ADR-019 §1): раскрываем 3×3-окно вокруг новой позиции —
        // саму клетку + 8 соседей по Чебышёву. Идемпотентно (де-дуп внутри).
        $this->exploredCellsModel->revealAround(
            (int) $character['id'],
            (int) $user['id'],
            (int) $targetCell['coordinate_x'],
            (int) $targetCell['coordinate_y'],
            isset($character['level']) ? (int) $character['level'] : null
        );

        // Теперь нужно заново нарисовать 12×12 карту, легенду и т.д.,
        // как в MoveCharacterAction
        $textMapService = new TextMapService();
        $updatedText    = $this->buildUpdatedMapText($character['id'], $textMapService);

        // Дополнительно можно приписать "Вы двинулись: {direction}"
        // Либо вписать в карту, на ваше усмотрение:
        $directionTranslated = $this->directionsTranslation[$direction] ?? $direction;
        $updatedText .= "\nВы двинулись на: *{$directionTranslated}*\n";

        // Кнопки те же самые, чтобы можно было продолжать двигаться
        $directionsKeyboard = [
            [
                ['text' => '↖️ Сев-Запад', 'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',     'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток','callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад', 'callback_data' => 'move_dir_west'],
                ['text' => '🏕',       'callback_data' => 'Base'],
                ['text' => '🧑‍🌾 🛠️','callback_data' => 'characterActions'],
                ['text' => '➡️ Восток','callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад','callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',      'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток','callback_data' => 'move_dir_southeast'],
            ],
            [
                ['text' => '🗺️ Поход', 'callback_data' => 'march'], // ADR-019
            ],
        ];

        // V25 (ADR-057) — если на этой клетке стоит активный караван, показать кнопку «🚚 Караван».
        if ((new \App\Services\Player\CaravanService())->enabled()) {
            $caravansHere = (new \App\Models\CaravanModel())->findActiveOnCell((int) $character['cell_number']);
            if (! empty($caravansHere)) {
                $directionsKeyboard[] = [
                    ['text' => '🚚 Караван', 'callback_data' => 'caravanLook'],
                ];
            }
        }

        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        // Редактируем ИМЕННО ТО сообщение, на котором нажали кнопку направления
        // (ADR-018 navTarget-паттерн) — а не «последнее» last_map_message_id: если
        // у игрока открыто несколько экранов «Переехать», правка должна попадать в
        // тот, где он жмёт. last_map_message_id остаётся вторым кандидатом.
        $clickedMsgId = $this->callbackQuery->getMessage()->getMessageId();
        $editTargets  = array_values(array_unique(array_filter([
            $clickedMsgId,
            $user['last_map_message_id'] ?? null,
        ])));

        foreach ($editTargets as $targetMsgId) {
            $editResponse = Request::editMessageText([
                'chat_id'      => $chatId,
                'message_id'   => $targetMsgId,
                'text'         => $updatedText,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);

            if ($editResponse->isOk()) {
                // Запоминаем фактически отредактированное сообщение как «карту».
                if ($targetMsgId !== ($user['last_map_message_id'] ?? null)) {
                    $this->telegramUserModel->update($user['id'], ['last_map_message_id' => $targetMsgId]);
                }
                $this->playerDetectionService->detectNearbyPlayers($character['id']);
                return $editResponse;
            }
        }

        // Если дошли сюда, значит либо нет last_map_message_id,
        // либо editMessageText вернул ошибку.
        // => отправляем новое сообщение
        $newMsgResponse = Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $updatedText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        if ($newMsgResponse->isOk()) {
            $result = $newMsgResponse->getResult();
            if (is_object($result) && method_exists($result, 'getMessageId')) {
                $messageId = $result->getMessageId();
                $this->telegramUserModel->update($user['id'], [
                    'last_map_message_id' => $messageId
                ]);
            }
        }

        $this->playerDetectionService->detectNearbyPlayers($character['id']);
        return $newMsgResponse;
    }

    protected $directionsTranslation = [
        'north'      => 'север',
        'south'      => 'юг',
        'west'       => 'запад',
        'east'       => 'восток',
        'northwest'  => 'северо-запад',
        'northeast'  => 'северо-восток',
        'southwest'  => 'юго-запад',
        'southeast'  => 'юго-восток',
    ];

    /**
     * Собираем заново 12×12 карту, легенду и т.д. для уже ОБНОВЛЁННОГО персонажа.
     * (аналогично тому, что делали в MoveCharacterAction)
     */
    protected function buildUpdatedMapText(int $characterId, TextMapService $textMapService): string
    {
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return "Ошибка: персонаж не найден!";
        }

        $text = "Куда пойдём? Выберите направление:\n\n";

        // Легенда
        $legend  = $textMapService->getLegend();
        $text   .= $legend . "\n";

        // Расстояние до базы
        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $text .= $distanceLine . "\n";
        }

        // Здоровье / усталость (уже обновлённые)
        $hp    = (float)($character['health'] ?? 0);
        $tired = (float)($character['tired'] ?? 0);
        $text .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        // 12×12 карта
        $mapOnly = $textMapService->buildMapOnly($character);
        $text   .= $mapOnly . "\n";

        // Координаты
        $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if ($mapRow) {
            $px = $mapRow['coordinate_x'];
            $py = $mapRow['coordinate_y'];
            $text .= "Игрок по центру (X={$px}, Y={$py})\n";
        }

        return $text;
    }
}
