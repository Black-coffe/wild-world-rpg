<?php

namespace App\TaskHandlers\Quests;

use App\Attributes\HandlerKey;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\TelegramUserModel;
use App\Services\Endgame\EndgameProgressionService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.38 (F2.9 batch-2 expansion): extends BaseTaskHandler.
 */
#[HandlerKey(
    key: 'quest_explore_300_cells',
    displayName: 'Квест: 300 клеток разведано',
    description: 'Recurring (Tasks.php every minute): tracks quest "Исследовать 300 клеток".',
)]
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
                // Получаем персонажа (для telegram_user_id ниже)
                $character = $this->characterModel->find($step['character_id']);
                if ($character) {
                    // Награда — атомарный relative-UPDATE от свежих значений
                    // (CharacterStatsService, fix lost-update 2026-07-13).
                    (new \App\Services\Player\CharacterStatsService())->adjust((int) $step['character_id'], [
                        'gold'       => 10000,
                        'experience' => 2,
                        'strength'   => 2,
                        'agility'    => 2,
                        'intellect'  => 2,
                    ]);

                    // Завершаем шаг квеста
                    $this->questStepsModel->update($step['id'], ['is_completed' => 1]);

                    // v0.51.116 endgame hook: quest completion → faction score.
                    (new EndgameProgressionService())->recordQuestCompletion((int) $step['character_id']);

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
        // ADR-150 (чистка дублей): награда за квест — не место для шести чужих кнопок.
        $keyboard = \App\Services\Telegram\NavKeyboards::simplified()
            ? \App\Services\Telegram\NavKeyboards::whatNextWith()
            : [
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
