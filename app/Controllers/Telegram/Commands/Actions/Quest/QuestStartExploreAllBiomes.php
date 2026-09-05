<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;

class QuestStartExploreAllBiomes extends BaseAction
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

        $quest = $questModel->where('title_en', 'ExploreAllBiomes')->first();

        if (!$quest) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Квест *Изучить все биомы* не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $quests = $questModel->getAvailableQuests($character['level']); // Получаем доступные квесты
        $activeQuestSteps = $questStepModel->getActiveQuestStepsForCharacter($characterId, $quests);

        // Проверяем, если у персонажа уже есть активные шаги для этого квеста
        if ($activeQuestSteps && in_array($quest['id'], array_column($activeQuestSteps, 'quest_id'))) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Квест *Изучить все биомы* уже был запущен.\nСмотрите его в разделе:\n*'🚀 Активные квесты'*\nили в разделе\n*'📅 Доступные квесты'*.\n",
                'parse_mode' => 'Markdown',
            ]);
        }

        try {
            // Создание записи в quest_steps
            $questStepModel->insert([
                'quest_id' => $quest['id'],
                'character_id' => $characterId,
                'step_order' => 1,
                'description' => 'Начало квеста: Изучить все биомы',
                'is_completed' => false
            ]);
        } catch (\Exception $e) {
            log_message('error', "Error creating quest step: " . $e->getMessage());
        }

        $rewardType = $this->translateRewardType($quest['reward_type']);
        $text = "🗺️ *Великий искатель приключений!*\n\n"
            . "Ты принял вызов квеста *{$quest['title_ru']}*. \nВ награду за твоё мастерство и смелость, при успешном завершении, ты получишь: *{$quest['reward']} $rewardType* 🏆.\n"
            . "\n🛡️ Отслеживай прогресс в разделе *'Активные квесты'*.\n"
            . "\n⚔️ Как только все испытания будут преодолены, квест закроется, и ты получишь заслуженные награды и честь.\n\n"
            . "🌟 _Пусть удача сопутствует тебе на этом пути!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        // #12 edit-in-place (ADR-018): «квест принят» — кнопка «Начать квест» жила на
        // списке/карточке квеста → редактируем то сообщение (fallback на новое при ошибке).
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
