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
 * Класс, отвечающий за вывод кнопок перемещения и
 * построение итогового текста в заданном порядке.
 */
class MoveCharacterAction
{
    protected $callbackQuery;

    // модели
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

        // Проверяем пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.'
            ]);
        }

        // Проверяем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден в базе.'
            ]);
        }

        // Проверка занятости
        $playerStateService = new PlayerStateService();
        if ($playerStateService->isGathering($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы заняты сбором ресурсов. Сначала дождитесь окончания сбора."
            ]);
        }
        if ($playerStateService->isExploring($character['id'])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Вы заняты исследованием территории. Сначала дождитесь окончания исследования."
            ]);
        }

        // inline‐клавиатура
        $directionsKeyboard = [
            [
                ['text' => '↖️ Сев-Запад', 'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',     'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток','callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад',     'callback_data' => 'move_dir_west'],
                ['text' => '🏕',            'callback_data' => 'Base'],
                ['text' => '🧑‍🌾 🛠️',        'callback_data' => 'characterActions'],
                ['text' => '➡️ Восток',    'callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад', 'callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',       'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток','callback_data' => 'move_dir_southeast'],
            ],
        ];

        $keyboard = ['inline_keyboard' => $directionsKeyboard];

        // Убираем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // ============================================
        // Формируем ПОРЯДОК вывода, как вы хотите
        // ============================================
        $textMapService = new TextMapService();

        // 1) «Куда пойдём? Выберите направление...»
        $finalText = "Куда пойдём? Выберите направление с клавиатуры ниже:\n";

        // 2) Легенда
        $legend    = $textMapService->getLegend();
        $finalText .= $legend . "\n";

        // 3) «От 🙎‍♂️ до 🏕 = ... ходов» (если есть)
        $distanceLine = $textMapService->getDistanceLine($character);
        if ($distanceLine) {
            $finalText .= $distanceLine . "\n";
        }

        // 4) Пример: здоровье/усталость
        $hp      = (float)($character['health'] ?? 0);
        $tired   = (float)($character['tired'] ?? 0);
        $finalText .= "❤️ Здоровье: {$hp}\n"
            . "💤 Усталость: {$tired}\n\n";

        // 5) КАРТА (12 строк)
        $mapOnly  = $textMapService->buildMapOnly($character);
        $finalText .= $mapOnly . "\n";

        // 6) Надпись «Игрок по центру (X=..., Y=...)»
        $mapRow = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if ($mapRow) {
            $px = $mapRow['coordinate_x'];
            $py = $mapRow['coordinate_y'];
            $finalText .= "Игрок по центру (X={$px}, Y={$py})";
        }

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
