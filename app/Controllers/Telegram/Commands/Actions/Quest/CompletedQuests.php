<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;

class CompletedQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $questModel = new QuestModel();
        $questStepModel = new QuestStepsModel();
        $characterModel = new CharacterModel();

        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // Получение списка завершенных квестов для данного персонажа
        $completedQuestSteps = $questStepModel->where('character_id', $characterId)
            ->where('is_completed', true)
            ->findAll();

        if (empty($completedQuestSteps)) {
            $text = "У вас нет завершенных квестов.";
        } else {
            $completedQuestIds = array_column($completedQuestSteps, 'quest_id');
            $completedQuests = $questModel->whereIn('id', $completedQuestIds)->findAll();

            $text = "*🏅 Завершенные квесты:*\n\n";
            foreach ($completedQuests as $quest) {
                $rewardType = $this->translateRewardType($quest['reward_type']);
                $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$rewardType}_)\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): список завершённых квестов — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function translateRewardType($type)
    {
        $translations = [
            'gold' => 'золото',
            'experience' => 'опыт',
            'items' => 'предметы',
        ];

        return $translations[$type] ?? $type;
    }
}
