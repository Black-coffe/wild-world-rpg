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

// Сервис для граф. карты (старый), пока закомментирован
use App\Services\World\MiniMapService;

// Сервис для текстовой карты
use App\Services\World\TextMapService;

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
        // 1) Получаем chatId, userId
        $chatId         = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // 2) Проверяем пользователя
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе.'
            ]);
        }

        // 3) Проверяем персонажа
        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден в базе.'
            ]);
        }

        // (Проверки здоровья, параллельные задачи и т.д. — опущены.)

        // 4) Кнопки для перемещения
        $directionsKeyboard = [
            [
                ['text' => '↖️ Сев-Запад', 'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',     'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток','callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад',     'callback_data' => 'move_dir_west'],
                ['text' => '➡️ Восток',    'callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад', 'callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',       'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток','callback_data' => 'move_dir_southeast'],
            ],
        ];
        $keyboard = [ 'inline_keyboard' => $directionsKeyboard ];

        // 5) Небольшой текст
        $text = "🚜 *Куда переедем?*\n"
            . "Выберите направление (учтите здоровье и выносливость).";

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // ======================================================
        // =============== СТАРЫЙ ГРАФИЧЕСКИЙ СЕРВИС =============
        // ======================================================
        /*
        $miniMapService = new MiniMapService();
        $tempFile = $miniMapService->generateLocalMiniMap($character);
        if ($tempFile && file_exists($tempFile)) {
            ...
        } else {
            ...
        }
        */

        // ======================================================
        // ============== НОВЫЙ ТЕКСТОВЫЙ СЕРВИС ================
        // ======================================================
        // Допустим, TextMapService::build12x12Map($character)
        // возвращает многострочный текст карты
        $textMapService = new TextMapService();
        $mapText = $textMapService->build12x12Map($character);

        // Формируем итоговое сообщение (текст + псевдо‐карта)
        $finalText = $text . "\n\n" . $mapText;

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $finalText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
