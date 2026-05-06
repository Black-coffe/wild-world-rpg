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
use App\Services\Player\PlayerStateService;
use App\Services\World\TextMapService;

/**
 * Класс, вызываемый при нажатии кнопки "Переехать" из основного меню "ДЕЙСТВИЯ".
 * Он отправляет НОВОЕ текстовое сообщение с картой (12×12),
 * легендой, здоровьем, и inline-кнопками направлений.
 *
 * Все последующие перемещения по кнопкам (move_dir_...) будут
 * лишь редактировать ЭТО сообщение, если получится.
 */
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

        // Отвечаем на колбэк, чтобы убрать "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Ищем telegram-пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.'
            ]);
        }

        // Ищем персонажа
        $character = $this->characterModel
            ->where('telegram_user_id', $user['id'])
            ->first();

        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден в базе.'
            ]);
        }

        // Проверяем, нет ли блокирующих задач (например, переезд, исследование, сбор)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            // Если идёт переезд — выходим. Сервис уже отправил сообщение.
            return Request::emptyResponse();
        }

        $playerStateService = new PlayerStateService();
        if ($playerStateService->isGathering($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы заняты сбором ресурсов. Дождитесь завершения."
            ]);
        }
        if ($playerStateService->isExploring($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы заняты исследованием территории. Дождитесь завершения."
            ]);
        }

        // 1) Строим большой текст с картой
        $textMapService = new TextMapService();
        $finalText = $this->buildMapText($character, $textMapService);

        // 2) Готовим inline-кнопки (8 направлений)
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
        ];
        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        // 3) Отправляем новое сообщение (sendMessage), т.к. это «первый показ»
        //    (в дальнейшем при нажатии «move_dir_...»
        //     мы будем редактировать это сообщение)
        $response = Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // 4) Если всё удачно, сохраним message_id
        if ($response->isOk()) {
            $result = $response->getResult();
            if (is_object($result) && method_exists($result, 'getMessageId')) {
                $messageId = $result->getMessageId();
                $this->telegramUserModel->update($user['id'], [
                    'last_map_message_id' => $messageId,
                ]);
            }
        }

        return $response;
    }

    /**
     * Собираем текст (12×12 карта + легенда + здоровье и т.д.)
     */
    protected function buildMapText(array|\App\Entities\CharacterEntity $character, TextMapService $textMapService): string
    {
        // Шапка
        $text = "Куда пойдём? Выберите направление с клавиатуры ниже:\n\n";

        // Легенда
        $legend  = $textMapService->getLegend();
        $text   .= $legend . "\n";

        // Расстояние до базы
        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $text .= $distanceLine . "\n";
        }

        // Здоровье / усталость
        $hp    = (float)($character['health'] ?? 0);
        $tired = (float)($character['tired'] ?? 0);
        $text .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        // Карта 12x12
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
