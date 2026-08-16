<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;
use App\Models\QuestModel;

class ActiveQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

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

        // Получаем запущенные и активные квесты для персонажа
        $activeQuestSteps = $questStepModel->where('character_id', $characterId)
            ->where('is_completed', false)
            ->findAll();

        if (empty($activeQuestSteps)) {
            $text = "На данный момент у вас нет активных квестов.";
        } else {
            $activeQuestIds = array_column($activeQuestSteps, 'quest_id');
            $activeQuests = $this->getActiveQuestsData($activeQuestIds);

            if (empty($activeQuests)) {
                $text = "На данный момент у вас нет активных квестов.";
            } else {
                $text = "*🚀 Активные квесты:*\n\n";
                foreach ($activeQuests as $quest) {
                    $rewardType = $this->translateRewardType($quest['reward_type']);
                    $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$rewardType}_)\n";
                }
            }
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
            ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
        ];

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // #12 edit-in-place (ADR-018): список активных квестов — навигация → редактируем
        // сообщение, на котором нажата кнопка (fallback на новое при ошибке/клике с photo-экрана).
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function getActiveQuestsData($activeQuestIds)
    {
        $questModel = new QuestModel();

        // Проверяем, не пуст ли массив идентификаторов квестов
        if (empty($activeQuestIds)) {
            return []; // Возвращаем пустой массив, если нет активных квестов
        }

        // Получаем данные о запущенных и активных квестах
        return $questModel->whereIn('id', $activeQuestIds)->findAll();
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
}
