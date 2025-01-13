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
        $chatId  = $message->getChat()->getId();
        $from    = $message->getFrom();
        $telegramId = $from->getId();

        // Извлекаем желаемое имя (после /name)
        $commandText = trim($message->getText());
        $name = trim(str_ireplace('/name', '', $commandText));

        // Если имя не указано
        if (empty($name)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❌ Пожалуйста, введите новое имя после команды `/name`.",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Проверка соответствия имени формату
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $name)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❌ Имя не соответствует правилам. Имя должно быть от 3 до 20 символов, включать только латинские буквы, цифры и подчеркивание (_).",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Ищем пользователя и персонажа
        $telegramUserModel = new TelegramUserModel();
        $characterModel    = new CharacterModel();

        $existingUser = $telegramUserModel->where('telegram_id', $telegramId)->first();
        if (!$existingUser) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ошибка: пользователь не найден в базе.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $character = $characterModel->where('telegram_user_id', $existingUser['id'])->first();
        if (!$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "Ошибка: персонаж не найден.",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Достаём нужные поля для логики
        $level         = (int) $character['level'];
        $hasRenamed    = (int) $character['has_renamed'];      // счётчик, сколько раз меняли (0,1,2)
        $lastNameChange= $character['last_name_change'];       // datetime или null

        // Подготовим время "сейчас" (для записи в last_name_change) и для расчётов
        $now = new \DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        // Флаг, что показывать курс обучения?
        $showTraining = false;
        // Сообщение, если нельзя сменить
        $cannotChangeReason = '';

        /**
         * Правила смены имени:
         * 1) level < 2 (уровень 1) + has_renamed=0: первая установка. Разрешаем. showTraining=true
         * 2) 2 <= level < 10 + has_renamed=1: вторая установка (однажды меняли). Разрешаем 1 раз.
         * 3) level >= 10: можно, если прошло 30+ дней с last_name_change.
         *    (только если has_renamed >=2 — но это не прописано явно, можно менять сколько угодно.
         *     Но в условии было "можно менять имя только раз в 30 дней" => логика).
         *
         * Если не подходит под эти условия — отказываем.
         */

        if ($level < 2) {
            // Предположим, это "первый раз" (регистрация)
            if ($hasRenamed === 0) {
                // Разрешаем менять
                $showTraining = true;
                $hasRenamed = 1;  // теперь 1
            } else {
                // has_renamed >=1 => уже меняли
                $cannotChangeReason = "Вы уже устанавливали имя. Повторная смена доступна с 2-го уровня.";
            }
        }
        elseif ($level < 10) {
            // Уровень от 2 до 9
            if ($hasRenamed === 1) {
                // Позволяем второй раз
                $hasRenamed = 2;
            } else {
                // Не подходит
                $cannotChangeReason = "Имя можно менять не более двух раз до 10-го уровня (у вас уже $hasRenamed).";
            }
        }
        else {
            // Уровень >= 10: имя можно менять (хоть 2+ раза),
            // но проверяем интервал 30 дней с момента last_name_change
            if (!$lastNameChange) {
                // Если вдруг null, значит ещё никогда не меняли: разрешаем
                // (если логика требует наоборот, уточните)
            } else {
                // Проверяем прошло ли 30 дней
                $lastChangeTime = new \DateTime($lastNameChange);
                $diff = $lastChangeTime->diff($now);
                $daysSince = $diff->days;  // Кол-во полных дней

                if ($daysSince < 30) {
                    $cannotChangeReason = "С момента предыдущей смены прошло только {$daysSince} дней. Нужно минимум 30 дней!";
                }
            }
        }

        // Если есть причина отказа, отправляем сообщение и выходим
        if ($cannotChangeReason) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "❌ Нельзя сменить имя: {$cannotChangeReason}",
                'parse_mode' => 'Markdown',
            ]);
        }

        // === Если дошли до сюда, значит смена разрешена ===

        // Обновляем поля: name, last_name_change, has_renamed (если надо)
        $updateData = [
            'name'             => $name,
            'last_name_change' => $nowStr,
        ];
        // has_renamed меняем только если < 2 и это не уровень >= 10:
        // но в условии выше мы уже выставили $hasRenamed.
        $updateData['has_renamed'] = $hasRenamed;

        $characterModel->update($character['id'], $updateData);

        // Готовим текст ответа
        if ($showTraining) {
            // Первый раз показываем обучение
            $textLine = "🎉 Ваше имя успешно установлено на: *{$name}*!\n\n"
                . "_Теперь пора переходить к делу, а именно пройти курс обучения для молодого бойца!_\n\n"
                . "Попутно ты узнаешь больше информации, получишь первый опыт, ресурсы и понимание механики, а также сеттинг игры.\n\n"
                . "_И помни, что пройдя обучение ты получишь знания и дополнительный опыт, что важно на старте._\n\n"
                . "🤖 *Приступаем!* ⬇️";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✖️ Без обучения',     'callback_data' => 'withoutTrainingStart'],
                        ['text' => '💡 Пройти обучение', 'callback_data' => 'getTrainedStart']
                    ],
                ]
            ];
        } else {
            // Уже не показываем обучение
            // Если уровень >=10, возможно, нужно сообщить про «смена раз в 30 дней»:
            $textLine = "🎉 Имя успешно изменено на: *{$name}*!\n\n";
            if ($level >= 10) {
                $textLine .= "_Следующую смену вы сможете сделать не раньше, чем через 30 дней._";
            } else {
                $textLine .= "_Это была ваша последняя смена имени до 10-го уровня._";
            }
            $keyboard = []; // Пустая или любая другая клавиатура
        }

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $textLine,
            'parse_mode' => 'Markdown',
            'reply_markup' => $keyboard ? json_encode($keyboard) : null,
        ]);
    }
}
