<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Models\CharacterModel;

class QuestStartFirstAidkitBasic extends BaseAction
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
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($character['level'] < 3) {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Квест *Крафт: Аптечки базовой* доступен с 3-го уровня.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $quest = $questModel->where('title_en', 'FirstAidkitBasic')->first();

        if (!$quest) {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Квест *Крафт: Аптечки базовой* не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($questStepModel->where(['quest_id' => $quest['id'], 'character_id' => $characterId])->first()) {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Вы уже начали квест *Крафт: Аптечки базовой*. Отслеживайте его статус в разделе *🚀 Активные квесты*.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $questStepModel->insert([
            'quest_id' => $quest['id'],
            'character_id' => $characterId,
            'step_order' => 1,
            'description' => 'Начало квеста на крафт базовой аптечки',
            'is_completed' => false
        ]);

        $text = "🔍 *Крафт: Аптечки базовой*\n\n";
        $text .= "📜 *Описание* 📜\n_Создай крафтовый, медицинский предмет_ *Аптечка базовая* _и как вознаграждение получи 1 500 золотых монет.\nКроме награды, ты окунешься в мир крафта и поймешь механику взаимосвязанных крафтовых предметов._\n*Важно!* _Не просто создай аптечку, но чтобы она побыла в инвентаре пару минут, тогда применится награда и закроется квест_\n\n";
        $text .= "🔒 *Условия*: Доступен с *3-го* уровня персонажа.\n\n";
        $text .= "🏆 *Награды*: 1 500 золотых монет.\n\n";
        $text .= "🔄 *Одноразовый*\n\n";
        $text .= "⏳ *Имеет срок выполнения или бессрочный*: Бессрочный";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
