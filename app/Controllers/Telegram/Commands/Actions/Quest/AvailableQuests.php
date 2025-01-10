<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;

class AvailableQuests extends BaseAction
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

        $quests = $questModel->getAvailableQuests($character['level'], $characterId); // Передача characterId
        // Используем:
        $activeQuestSteps = $questStepModel->getActiveQuestStepsForCharacter($characterId, $quests);

        $availableQuests = [];
        foreach ($quests as $quest) {
            // Проверяем наличие соответствующей записи в таблице quest_steps
            $questStep = $questStepModel->where('quest_id', $quest['id'])
                ->where('character_id', $characterId)
                ->first();
            // Если запись найдена, то пропускаем текущий квест
            if ($questStep) {
                continue;
            }

            if (!in_array($quest['id'], $activeQuestSteps)) {
                $availableQuests[] = $quest;
            }
        }

        if (empty($availableQuests)) {
            $text = "На данный момент нет доступных квестов. Проверьте позже!";
        } else {
            $text = "*📜 Доступные квесты:*\n\n";
            foreach ($availableQuests as $quest) {
                $rewardType = $this->translateRewardType($quest['reward_type']);
                $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$rewardType}_)\n";
            }
            $text .= "\nВыбери квест и отправляйся к приключениям!";
        }

        $keyboard = $this->generateQuestKeyboard($availableQuests);
        //log_message('debug', "keyboard: " . print_r($keyboard, true));
        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage([
            'chat_id' => $chatId,
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
            'items' => 'предметы',  // Пример добавления другого типа награды
        ];

        return $translations[$type] ?? $type;  // Возвращаем перевод или оригинальное значение, если перевод отсутствует
    }

    private function generateQuestKeyboard($quests)
    {
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($quests as $quest) {
            $row[] = ['text' => $quest['title_ru'], 'callback_data' => 'questStart' . $quest['title_en']];
            if (count($row) == 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }

        // Add standard buttons
        $keyboard['inline_keyboard'][] = [
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ];

        return $keyboard;
    }
}
