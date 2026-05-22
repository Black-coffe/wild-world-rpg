<?php

namespace App\Controllers\Telegram\Commands;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// не ReplyKeyboardMarkup, а именно Keyboard

// Подключаем ваш CharacterService

class StartCommand extends UserCommand
{
    protected $name        = 'start';
    protected $description = 'Start command';
    protected $usage       = '/start';
    protected $version     = '1.2.0';

    public function execute(): ServerResponse
    {
        $message    = $this->getMessage();
        $chatId     = $message->getChat()->getId();
        $from       = $message->getFrom();
        $telegramId = $from->getId();
        $username   = $from->getUsername();
        $firstName  = $from->getFirstName();
        $lastName   = $from->getLastName();

        // 1. Закрепляем постоянную клавиатуру
        $replyKeyboard = new Keyboard([
            'keyboard' => [
                // Каждая вложенная строка массива - это одна горизонтальная строка кнопок
                ['Перс', 'База', 'Крафт', 'Карта'],
                ['Настройки'], // идея #14 — экран «⚙️ Настройки» (тумблер картинок)
            ],
            'resize_keyboard'   => true,  // сжимаем клавиатуру под кнопки
            'one_time_keyboard' => false, // не скрывать после нажатия
            'selective'         => false, // показывать всем
        ]);

        // 2. Проверяем/создаём пользователя
        $telegramUserModel = new TelegramUserModel();
        $existingUser      = $telegramUserModel->where('telegram_id', $telegramId)->first();
        if (!$existingUser) {
            $createdUserId = $telegramUserModel->insert([
                'telegram_id' => $telegramId,
                'username'    => $username,
                'first_name'  => $firstName,
                'last_name'   => $lastName,
            ], true);
        } else {
            $createdUserId = $existingUser['id'];
        }

        // 3. Ищем персонажа
        $characterModel   = new CharacterModel();
        $existingCharacter= $characterModel->where('telegram_user_id', $createdUserId)->first();

        // 4. Если персонаж НЕ найден → создаём и отправляем приветственное сообщение
        if (!$existingCharacter) {
            $createdCharacterId = $characterModel->insert([
                'telegram_user_id' => $createdUserId,
                'name'        => $username ?: 'Unknown Hero',
                'level'       => 1,
                'experience'  => 0.01,
                'health'      => 100,
                'tired'       => 100,
                'strength'    => 0.01,
                'agility'     => 0.01,
                'intellect'   => 0.01,
                'gold'        => 1000,
                'cell_number' => null,
            ], true);

            $mapModel   = new MapModel();
            $biomeModel = new BiomeModel();

            // Пытаемся заспавнить в одной из допустимых ячеек
            $allowedBiomes = [1, 2, 3, 5, 6, 7, 8, 9];
            $spawnCells = $mapModel
                ->where('coordinate_y >=', 900)
                ->whereIn('biome_id', $allowedBiomes)
                ->findAll();

            // Сообщение по умолчанию на случай ошибки
            $text          = "Извините, произошла ошибка при попытке определить локацию для спавна. Пожалуйста, попробуйте ещё раз.";
            $encodedKeyboard = json_encode([]);

            if (!empty($spawnCells)) {
                $randomCell = $spawnCells[array_rand($spawnCells)];
                $cellNumber = $randomCell['cell_number'];

                // Обновляем персонажу cell_number
                $characterModel->update($createdCharacterId, ['cell_number' => $cellNumber]);

                // Формируем приветственное сообщение
                $text = "🤖 *Wild World — выживание на острове после глобальной катастрофы.* 🌍\n\n"
                    . "Меня зовут *Роби*, я — твой проводник. Мы оказались на остатке земли, где всё рухнуло: цивилизация ушла, выжившие сами разбираются, как жить дальше.\n\n"
                    . "Что тебя ждёт:\n"
                    . "• 9 биомов — от спокойных полей до вулканических земель и подземелий\n"
                    . "• Сбор ресурсов, крафт, постройка лагеря — всё из подручного хлама\n"
                    . "• События мира: погода, болезни, рейдеры, редкие находки\n"
                    . "• На lvl 10 — выбор одной из 4 фракций. У каждой свой путь: доминирование, анархия, научный прорыв или мирное возрождение.\n\n"
                    . "Не все выжившие дружелюбны — продумывай, как защитить себя и базу.\n\n"
                    . "Сейчас покажу несколько подсказок, чтобы ты не блуждал в первый час. Можно прервать в любой момент — но советую дойти до конца.\n\n"
                    . "Вопросы и стратегии обсуждаем в [общем чате](https://t.me/wild_world_info).\n\n"
                    . "Придумай герою имя и поехали.";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🤔 Задать имя персонажа', 'callback_data' => 'setCharacterName']
                        ],
                    ]
                ];
                $encodedKeyboard = json_encode($keyboard);
            }

            // N4 (ADR-039): постоянную reply-клавиатуру шлём только новичку. Для
            // существующих игроков её ставит CharacterService::showCharacterInfo —
            // раньше StartCommand слал её безусловно → дубль keyboard-сообщения на /start.
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => 'Добро пожаловать! Используйте меню ниже для выбора действия.',
                'reply_markup' => $replyKeyboard,
            ]);

            // Возвращаем результат
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
                'reply_markup' => $encodedKeyboard
            ]);

        } else {
            // Если персонаж УЖЕ существует → используем CharacterService
            $charService = new CharacterService();
            return $charService->showCharacterInfo($chatId, $existingCharacter);
        }
    }
}
