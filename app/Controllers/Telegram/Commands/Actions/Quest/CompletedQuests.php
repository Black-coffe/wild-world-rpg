<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\Quest\QuestListService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class CompletedQuests extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // w2-n5-deeds-02: персонаж — по отправителю (BaseAction), не по chat_id (у веб-моста чат
        // виртуальный); список — из нейтрального ядра QuestListService, общего с вебом.
        [, $character] = $this->getUserAndCharacter();
        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        if ($characterId <= 0) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $completedQuests = (new QuestListService())->completed($characterId);

        if (empty($completedQuests)) {
            $text = "У вас нет завершенных квестов.";
        } else {
            $text = "*🏅 Завершенные квесты:*\n\n";
            foreach ($completedQuests as $quest) {
                $text .= "🔹 *{$quest['title_ru']}* || Награда: *{$quest['reward']}* (_{$quest['reward_type_ru']}_)\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                    ['text' => '◀️ Я', 'callback_data' => 'character'],
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
}
