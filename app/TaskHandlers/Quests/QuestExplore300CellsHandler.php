<?php

namespace App\TaskHandlers\Quests;

use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\TelegramUserModel;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.38 (F2.9 batch-2 expansion): extends BaseTaskHandler.
 */
class QuestExplore300CellsHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $telegramUserModel;
    protected $questModel;
    protected $questStepsModel;
    protected $exploredCellsModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->questModel = new QuestModel();
        $this->questStepsModel = new QuestStepsModel();
        $this->exploredCellsModel = new ExploredCellsModel();
    }

    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature.
     */
    public function handle(array $task = []): void
    {
        $quest = $this->questModel->where('title_en', 'Explore300Cells')->first();
        if (!$quest) {
            return;
        }

        $questSteps = $this->questStepsModel
            ->where('quest_id', $quest['id'])
            ->where('is_completed', 0)
            ->findAll();

        foreach ($questSteps as $step) {
            $exploredCellsCount = $this->exploredCellsModel->where('character_id', $step['character_id'])->countAllResults();

            if ($exploredCellsCount >= 300) {
                // Получаем текущие значения персонажа
                $character = $this->characterModel->find($step['character_id']);
                if ($character) {
                    // Вычисляем новые значения
                    $newGold = $character['gold'] + 10000;
                    $newExperience = $character['experience'] + 2;
                    $newStrength = $character['strength'] + 2;
                    $newAgility = $character['agility'] + 2;
                    $newIntellect = $character['intellect'] + 2;

                    // Обновляем данные персонажа
                    $this->characterModel->update($step['character_id'], [
                        'gold' => $newGold,
                        'experience' => $newExperience,
                        'strength' => $newStrength,
                        'agility' => $newAgility,
                        'intellect' => $newIntellect
                    ]);

                    // Завершаем шаг квеста
                    $this->questStepsModel->update($step['id'], ['is_completed' => 1]);

                    // Отправляем сообщение о завершении квеста
                    $message = "🎉 *Поздравляем!* Квест '*Изучить 300 ячеек*' успешно завершен! Ты заслужил награду в *10000 золотых монет* и значительно увеличил свои навыки!\n\nПразднуй свою победу, герой! Новые приключения уже ждут тебя!";
                    $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
                    $this->sendMessage($telegramUserId, $message);
                }
            }
        }
    }

    protected function sendMessage($telegramUserId, $message): void
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
        $imagePath = base_url('uploads/telegram/quests/explore_30cells.jpg');

        $this->safeSendPhoto(
            $telegramUserId,
            $imagePath,
            $message,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
