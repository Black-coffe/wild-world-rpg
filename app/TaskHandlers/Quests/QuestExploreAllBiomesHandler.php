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
    key: 'quest_explore_all_biomes',
    displayName: 'Квест: побывать во всех биомах',
    description: 'Recurring (Tasks.php every minute): tracks quest "Побывать во всех биомах" (9 биомов).',
)]
class QuestExploreAllBiomesHandler extends BaseTaskHandler
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
        $quest = $this->questModel->where('title_en', 'ExploreAllBiomes')->first();
        if (!$quest) {
            return;
        }

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

                // v0.51.116 endgame hook: quest completion → faction score.
                (new EndgameProgressionService())->recordQuestCompletion((int) $step['character_id']);

                // Send the completion message
                $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
                $message = "🌟 *Поздравляем!*\n\nТы успешно исследовал все биомы!\nТвой опыт увеличен на 2 единицы. Новые приключения уже ждут тебя!";

                $this->sendMessage($telegramUserId, $message);
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
        $imagePath = base_url('uploads/telegram/quests/explore_allBiomes_finish.jpg');

        $this->safeSendPhoto(
            $telegramUserId,
            $imagePath,
            $message,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
