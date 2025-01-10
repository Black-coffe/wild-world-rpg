<?php

namespace App\TaskHandlers\Quests;

use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class QuestExplore30CellsHandler
{
    protected $characterModel;
    protected $telegramUserModel;
    protected $questModel;
    protected $questStepsModel;
    protected $exploredCellsModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->questModel = new QuestModel();
        $this->questStepsModel = new QuestStepsModel();
        $this->exploredCellsModel = new ExploredCellsModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        // 1. Получаем строку с нужным квестом
        $quest = $this->questModel->where('title_en', 'Explore30Cells')->first();

        // 2. Получаем всех игроков (персонажей) с заданным квестом
        $questSteps = $this->questStepsModel
            ->where('quest_id', $quest['id'])
            ->where('is_completed', 0)
            ->findAll();

        foreach ($questSteps as $step) {
            // 3. Получаем количество изученных ячеек для каждого персонажа
            $exploredCellsCount = $this->exploredCellsModel->where('character_id', $step['character_id'])->countAllResults();

            // 4. Делаем разбивку по количеству и отправляем сообщения
            if ($exploredCellsCount >= 13 && $exploredCellsCount <= 20 and $step['step_order'] != 11) {
                $remainingCells = 30 - $exploredCellsCount;
                $message = "🔍 *Поздравляем!* Ты уже прошел половину пути к завершению квеста '*Изучить 30 ячеек*'. Осталось исследовать всего лишь *$remainingCells* ячеек!\n\nНастройся на приключение, твоя удача скоро повернется!";
                $this->questStepsModel->update($step['id'], ['step_order' => 11]);
            } elseif ($exploredCellsCount >= 30) {
                // 5. Отправляем сообщение об успешном выполнении квеста
                $this->characterModel->increaseGold($step['character_id'], 1000);
                $this->questStepsModel->completeStep($step['id']);
                $message = "🎉 *Поздравляем!* Квест '*Изучить 30 ячеек*' успешно завершен! Ты заслужил награду в *1000 золотых монет*!\n\nПразднуй свою победу, герой! Новые приключения уже ждут тебя!";
                // Обновляем порядок шага на 11, чтобы сообщение больше не отправлялось
            } else {
                // Не отправляем сообщение, если не выполнены условия
                continue;
            }

            // Отправляем сообщение в Telegram
            $character = $this->characterModel->where('id', $step['character_id'])->first();
            $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
            $this->sendMessage($telegramUserId, $message);
        }
    }

    protected function sendMessage($telegramUserId, $message)
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
                [
                    ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/quests/explore_30cells.jpg'); // Укажите актуальный путь к изображению

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $telegramUserId]);

        // Отправляем сообщение с картинкой и клавиатурой
        return Request::sendPhoto([
            'chat_id' => $telegramUserId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
