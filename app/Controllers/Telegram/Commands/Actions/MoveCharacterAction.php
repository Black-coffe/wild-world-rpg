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

// Подключаем PlayerStateService
use App\Services\Player\PlayerStateService;

// Сервис для текстовой карты
use App\Services\World\TextMapService;

/**
 * Класс, отвечающий за вывод кнопок перемещения и отрисовку мини‐карты.
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

        // ============================
        // 4) Проверка занятости игрока
        // ============================
        // Если игрок выполняет задачу "Gather" (сбор) или "ExploreTheArea" (изучение),
        // запрещаем перемещение.

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

        // Если все проверки пройдены, продолжаем:
        // Формируем inline‐клавиатуру с направлениями + кнопка «База».
        $directionsKeyboard = [
            [
                ['text' => '↖️ Сев-Запад',  'callback_data' => 'move_dir_northwest'],
                ['text' => '⬆️ Север',      'callback_data' => 'move_dir_north'],
                ['text' => '↗️ Сев-Восток', 'callback_data' => 'move_dir_northeast'],
            ],
            [
                ['text' => '⬅️ Запад',      'callback_data' => 'move_dir_west'],
                // Новая кнопка «База»:
                ['text' => '🏕 База',       'callback_data' => 'Base'],
                ['text' => '➡️ Восток',     'callback_data' => 'move_dir_east'],
            ],
            [
                ['text' => '↙️ Юго-Запад',  'callback_data' => 'move_dir_southwest'],
                ['text' => '⬇️ Юг',        'callback_data' => 'move_dir_south'],
                ['text' => '↘️ Юго-Восток', 'callback_data' => 'move_dir_southeast'],
            ],
        ];
        $keyboard = [ 'inline_keyboard' => $directionsKeyboard ];

        // Небольшой текст-приглашение
        $text = "🚜 *Куда переедем?*\n"
            . "Выберите направление (учтите здоровье и выносливость).";

        // Ответ на callback (чтобы убрать "часики" в интерфейсе Telegram)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // =================================
        // 5) Отрисовка текстовой мини‐карты
        // =================================
        $textMapService = new TextMapService();
        $mapText = $textMapService->build12x12Map($character);

        // Формируем итоговое сообщение (текст + псевдо-карта)
        $finalText = $text . "\n\n" . $mapText;

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $finalText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
