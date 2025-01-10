<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use App\Models\ExploredCellsModel;
use App\Services\PlayerDetectionService; // Добавляем PlayerDetectionService

class MoveNewLocationToWest
{
    protected $callbackQuery;
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;
    protected $playerDetectionService; // Добавляем PlayerDetectionService

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel = new BiomeModel();
        $this->playerDetectionService = new PlayerDetectionService(); // Инициализация PlayerDetectionService
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Поиск пользователя и персонажа
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден в базе данных.']);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден или не имеет локации.']);
        }

        // Получение текущей локации персонажа и определение западной ячейки
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Локация персонажа не найдена.']);
        }

        $westCell = $this->mapModel
            ->where('coordinate_x', $currentCell['coordinate_x'] - 1)
            ->where('coordinate_y', $currentCell['coordinate_y'])->first();

        if (!$westCell) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Западная локация не найдена.']);
        }

        // Проверка, исследована ли западная ячейка
        $explored = $this->exploredCellsModel->where('character_id', $character['id'])->where('map_cell_id', $westCell['id'])->first();
        if (!$explored) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                        ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Запад - локация не исследована! Невозможно переехать.',
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Перемещение персонажа в западную ячейку
        $this->characterModel->update($character['id'], [
            'cell_number' => $westCell['cell_number'],
            'biome_id' => $westCell['biome_id'], // Добавляем ID биома новой локации
            // Увеличение параметров
            'strength' => $character['strength'] + 0.1,
            'agility' => $character['agility'] + 0.1,
            'experience' => $character['experience'] + 0.05,
            // Уменьшение параметров
            'health' => $character['health'] - 5,
            'tired' => $character['tired'] - 10,
            'intellect' => $character['intellect'] - 0.05
        ]);

        // Получение информации о биоме
        $biome = $this->biomeModel->find($westCell['biome_id']);
        if (!$biome) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Информация о биоме не найдена.']);
        }

        // Отправка сообщения об успешном переезде
        $text = "🚚 *Путешественник, ликуй! Ты успешно добрался на Запад*.\n\n"
            . "🔲 *Твоя игровая ячейка: №*" . $westCell['cell_number'] . "\n\n"
            . "🧭 *Координаты: X* " . $westCell['coordinate_x'] . "* | Y* " . $westCell['coordinate_y'] . "\n\n"
            . "🌿 *Текущий биом:* " . $biome['name'] . "\n\n"
            . "⚠️ *Уровень опасности:* " . $biome['danger_level'] . "\n\n"
            . "💪 *Сложность выживания:* " . $biome['survival_difficulty'] . "\n\n"
            . "🗺️ *Не забудь взглянуть на карту мира, чтобы сориентироваться!* 🗺️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/map-lines-coordinates.jpg'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $response = Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Интеграция PlayerDetectionService: вызываем метод для обнаружения ближайших игроков
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return $response;
    }
}
