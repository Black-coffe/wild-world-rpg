<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\QuestModel;
use App\Models\QuestStepsModel;
use App\Services\Quest\QuestStartService;

class QuestStartFirstAidkitBasic extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $questModel = new QuestModel();
        $questStepModel = new QuestStepsModel();

        // w2-n5-deeds-01: персонаж — по отправителю (BaseAction), не по chat_id: у веб-моста чат виртуальный.
        [, $character] = $this->getUserAndCharacter();
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        if ($character['level'] < 3) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Квест *Крафт: Аптечки базовой* доступен с 3-го уровня.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $quest = $questModel->where('title_en', 'FirstAidkitBasic')->first();

        if (!$quest) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Квест *Крафт: Аптечки базовой* не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $alreadyText = "Вы уже начали квест *Крафт: Аптечки базовой*. Отслеживайте его статус в разделе *🚀 Активные квесты*.";

        if ($questStepModel->where(['quest_id' => $quest['id'], 'character_id' => $characterId])->first()) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $alreadyText,
                'parse_mode' => 'Markdown',
            ]);
        }

        // w2-n5-deeds-01: проверка выше — быстрый путь; окончательная — в ядре под блокировкой строки
        // персонажа: двойной тап или бот+веб одновременно не создают второй строки quest_steps.
        if (! (new QuestStartService())->claimFirstStep($characterId, (int) $quest['id'], 'Начало квеста на крафт базовой аптечки')) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $alreadyText,
                'parse_mode' => 'Markdown',
            ]);
        }

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
}
