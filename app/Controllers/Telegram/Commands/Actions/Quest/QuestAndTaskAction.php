<?php

namespace App\Controllers\Telegram\Commands\Actions\Quest;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class QuestAndTaskAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $text = "*📜 Раздел 'Квесты и Задания'!*\n\n"
            . "Здесь ты формируешь свою судьбу и добиваешься величия через выполнение квестов.\n"
            . "Вот что тебя ждет:\n\n"
            . "*🚀 Активные квесты*\n"
            . "_Текущие задания, которые ты взял на выполнение._\n\n"
            . "*📅 Доступные квесты*\n"
            . "_Новые вызовы, которые ты можешь начать прямо сейчас._\n\n"
            . "*🏅 Завершенные квесты*\n"
            . "_Все твои достижения и награды за прошлые квесты._\n\n"
            . "*👨‍🎤 Персонаж*\n"
            . "_Информация о твоем персонаже и его текущем статусе._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🚀 Активные квесты', 'callback_data' => 'activeQuests'],
                    ['text' => '📅 Доступные квесты', 'callback_data' => 'availableQuests'],
                ],
                [
                    ['text' => '🏅 Завершенные квесты', 'callback_data' => 'completedQuests'],
                    ['text' => '🌐 Квестомания', 'callback_data' => 'questInfo'],
                ],
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                ]
            ]
        ];

        $imagePath = base_url('uploads/telegram/quests/Quests-and-Missions.jpg'); // Укажите актуальный путь к изображению

        // Ответ на callback запрос, чтобы убрать часики на кнопке
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Отправляем сообщение с картинкой и клавиатурой
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
