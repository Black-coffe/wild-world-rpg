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

class QuestExploreAllBiomesHandler
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
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        $quest = $this->questModel->where('title_en', 'ExploreAllBiomes')->first();

        $questSteps = $this->questStepsModel->where('quest_id', $quest['id'])->where('is_completed', 0)->findAll();

        foreach ($questSteps as $step) {
            $exploredBiomes = $this->exploredCellsModel->select('biome_id')->distinct()->where('character_id', $step['character_id'])->findAll();
            $uniqueBiomesCount = count($exploredBiomes);

            if ($uniqueBiomesCount >= 9) {
                // Update character experience
                $character = $this->characterModel->find($step['character_id']);
                $newExperience = $character['experience'] + 2;
                $this->characterModel->update($step['character_id'], ['experience' => $newExperience]);

                // Complete the quest step
                $this->questStepsModel->update($step['id'], ['is_completed' => 1]);

                // Send the completion message
                $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
                $message = "🌟 *Поздравляем!*\n\nТы успешно исследовал все биомы!\nТвой опыт увеличен на 2 единицы. Новые приключения уже ждут тебя!";

                $this->sendMessage($telegramUserId, $message);
            }
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
                    ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/quests/explore_allBiomes_finish.jpg'); // Укажите актуальный путь к изображению

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
