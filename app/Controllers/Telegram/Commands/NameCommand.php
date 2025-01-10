<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;

class NameCommand extends UserCommand
{
    protected $name = 'name';
    protected $description = 'Sets a new name for the character';
    protected $usage = '/name <new_name>';
    protected $version = '1.2.1';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();
        $from = $message->getFrom();
        $telegramId = $from->getId();

        // Получаем текст команды и удаляем префикс `/name`
        $commandText = trim($message->getText());
        $name = trim(str_ireplace('/name', '', $commandText));

        // Если имя не было указано после команды
        if (empty($name)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ Пожалуйста, введите новое имя после команды `/name`.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        // Проверка, чтобы имя соответствовало правилам (только латинские буквы, цифры и _)
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $name)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ Имя не соответствует правилам. Имя должно быть от 3 до 20 символов, включать только латинские буквы, цифры и подчеркивание (_).",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        // Проверка пользователя и его персонажа
        $telegramUserModel = new TelegramUserModel();
        $characterModel = new CharacterModel();

        $existingUser = $telegramUserModel->where('telegram_id', $telegramId)->first();
        if (!$existingUser) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Ошибка: пользователь не найден в базе.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        $character = $characterModel->where('telegram_user_id', $existingUser['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "Ошибка: персонаж не найден.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        // Обновление имени персонажа
        $characterModel->update($character['id'], ['name' => $name]);

        // Отправляем сообщение пользователю с обновленным именем
        $text = "🎉 Ваше имя успешно обновлено на: *{$name}*!";
        $message = "🤖 Это снова я – *Роби*!\n\n"
            . "{$text}\n\n"
            . "_Теперь пора переходить к делу, а именно пройти курс обучения для молодого бойца!_\n\n"
            . "Попутно ты узнаешь больше информации, получишь первый опыт, ресурсы и понимание механики, а также сеттинг игры.\n\n"
            . "_И помни, что пройдя обучение ты получишь знания и дополнительный опыт,что важно на старте._\n\n"
            . "🤖 *Приступаем!* ⬇️";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✖️ Без обучения', 'callback_data' => 'withoutTrainingStart'],
                    ['text' => '💡 Пройти обучение', 'callback_data' => 'getTrainedStart']
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
