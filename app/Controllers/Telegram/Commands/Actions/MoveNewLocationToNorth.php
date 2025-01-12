<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Models\ExploredCellsModel;
use App\Models\BiomeModel;
use App\Services\PlayerDetectionService;

class MoveNewLocationToNorth
{
    protected $callbackQuery;
    protected $characterModel;
    protected $mapModel;
    protected $telegramUserModel;
    protected $exploredCellsModel;
    protected $biomeModel;
    protected $playerDetectionService;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery = $callbackQuery;
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->biomeModel = new BiomeModel();
        $this->playerDetectionService = new PlayerDetectionService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = $this->callbackQuery->getFrom()->getId();

        // Поиск пользователя и персонажа
        $user = $this->telegramUserModel->where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных.'
            ]);
        }

        $character = $this->characterModel->where('telegram_user_id', $user['id'])->first();
        if (!$character || !$character['cell_number']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден или не имеет локации.'
            ]);
        }

        // Получение текущей локации персонажа и определение северной ячейки
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        if (!$currentCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Локация персонажа не найдена.'
            ]);
        }

        $northCell = $this->mapModel
            ->where('coordinate_x', $currentCell['coordinate_x'])
            ->where('coordinate_y', $currentCell['coordinate_y'] - 1)
            ->first();

        if (!$northCell) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Северная локация не найдена.'
            ]);
        }

        // Проверка, исследована ли северная ячейка
        $explored = $this->exploredCellsModel
            ->where('character_id', $character['id'])
            ->where('map_cell_id', $northCell['id'])
            ->first();

        if (!$explored) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ],
                ]
            ];
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Северная локация не исследована. Невозможно переехать.',
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Перемещение персонажа в северную ячейку
        $updateData = [
            'cell_number' => $northCell['cell_number'],
            'biome_id' => $northCell['biome_id'], // Добавляем ID биома новой локации
            // Увеличение параметров
            'strength' => $character['strength'] + 0.1,
            'agility' => $character['agility'] + 0.1,
            'experience' => $character['experience'] + 0.05,
            // Уменьшение параметров
            'health' => max($character['health'] - 5, 0),
            'tired' => max($character['tired'] - 10, 0),
            'intellect' => max($character['intellect'] - 0.05, 0)
        ];

        $this->characterModel->update($character['id'], $updateData);

        // Получение информации о биоме
        $biome = $this->biomeModel->find($northCell['biome_id']);
        if (!$biome) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Информация о биоме не найдена.'
            ]);
        }

        // Формирование текста сообщения
        $text = "🚚 *Путешественник, ликуй! Ты успешно добрался на Север*.\n\n"
            . "🔲 *Твоя игровая ячейка: №*" . $northCell['cell_number'] . "\n\n"
            . "🧭 *Координаты: X* " . $northCell['coordinate_x'] . "* | Y* " . $northCell['coordinate_y'] . "\n\n"
            . "🌿 *Текущий биом:* " . $biome['name'] . "\n\n"
            . "⚠️ *Уровень опасности:* " . $biome['danger_level'] . "\n\n" // Предположим, что это поле называется 'danger_level'
            . "💪 *Сложность выживания:* " . $biome['survival_difficulty'] . "\n\n" // И это поле 'survival_difficulty'
            . "🗺️ *Не забудь взглянуть на карту мира, чтобы сориентироваться!* 🗺️\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        $imagePath = FCPATH . 'uploads/telegram/map-lines-coordinates.jpg'; // Укажите актуальный путь к изображению

        // Проверка существования изображения
        if (!file_exists($imagePath)) {
            log_message('error', "Изображение {$imagePath} не найдено.");
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Отправка фото с информацией о локации
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $response = Request::sendPhoto([
            'chat_id' => $chatId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Интеграция PlayerDetectionService
        $this->playerDetectionService->detectNearbyPlayers($character['id']);

        return $response;
    }
}
