<?php

namespace App\TaskHandlers\Quests;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TelegramUserModel;
use App\Services\Endgame\EndgameProgressionService;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.38 (F2.9 batch-2 expansion): extends BaseTaskHandler.
 */
#[HandlerKey(
    key: 'quest_first_aid_kit_basic',
    displayName: 'Квест: базовая аптечка',
    description: 'Recurring (Tasks.php every minute): tracks quest "Скрафтить базовую аптечку".',
)]
class QuestFirstAidkitBasicHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $questModel;
    protected $questStepsModel;
    protected $telegramUserModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->questModel = new QuestModel();
        $this->questStepsModel = new QuestStepsModel();
        $this->craftedItemsModel = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature.
     */
    public function handle(array $task = []): void
    {
        $quest = $this->questModel->where('title_en', 'FirstAidkitBasic')->first();
        if (!$quest) {
            log_message('error', "Квест Крафт аптечки базовой не найден в базе");
            return;
        }

        $questSteps = $this->questStepsModel->where('quest_id', $quest['id'])->where('is_completed', 0)->findAll();
        foreach ($questSteps as $step) {
            $characterId = $step['character_id'];
            $character = $this->characterModel->find($characterId);
            if (!$character) {
                log_message('error', "Персонаж с таким ID не найден в базе: {$characterId}");
                continue;
            }

            $firstAidKitItem = $this->craftedItemsModel->getRowByName('FirstAidKit');
            if (!$firstAidKitItem) {
                log_message('error', "Не найден в базе данных пункт FirstAidKit");
                continue;
            }

            $logEntry = $this->craftedItemsLogModel->getItemByCraftedItemIdAndCharacterId($firstAidKitItem['id'], $characterId);
            if ($logEntry && $logEntry['quantity'] >= 1) {
                // Complete the quest
                $this->questStepsModel->update($step['id'], ['is_completed' => 1]);

                // v0.51.116 endgame hook: quest completion → faction score.
                (new EndgameProgressionService())->recordQuestCompletion((int) $characterId);

                // Update character's gold
                $this->characterModel->increaseGold($characterId, 1500);

                // Send completion message
                $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];
                $message = "🌟 *Поздравляем!*\n\nТы успешно скрафтил свою первую аптечку!\nТвой запас золота вырос на *1500* единиц.\nНовые приключения уже ждут тебя!";

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
        $imagePath = base_url('uploads/telegram/craft/simple_craft_kit.jpg');

        $this->safeSendPhoto(
            $telegramUserId,
            $imagePath,
            $message,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
