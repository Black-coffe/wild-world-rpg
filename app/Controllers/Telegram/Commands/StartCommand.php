<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Keyboard; // не ReplyKeyboardMarkup, а именно Keyboard
use App\Models\TelegramUserModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use DateTime;

// Подключаем ваш CharacterService
use App\Services\CharacterService;

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
            ],
            'resize_keyboard'   => true,  // сжимаем клавиатуру под кнопки
            'one_time_keyboard' => false, // не скрывать после нажатия
            'selective'         => false, // показывать всем
        ]);

        // Отправляем сообщение для отображения клавиатуры
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => 'Добро пожаловать! Используйте меню ниже для выбора действия.',
            'reply_markup' => $replyKeyboard,
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
                $text = "🎉 *Добро пожаловать в Wild World!* 🌍\n\n"
                    . "**Внимание:** Перед началом приключения обязательно *прочитай всё* и пройди обучение — *без этого никак!* 🏆\n\n"
                    . "Приветствую тебя, путник! Меня зовут 🤖 *Роби*, и я буду твоим проводником в этом мире. Мы оказались на неизведанном острове после глобальной катастрофы.\n\n"
                    . "💥 Здесь всё изменилось: уникальные биомы, неожиданные встречи и суровая борьба за ресурсы. Тебе предстоит выживать, развивать навыки и строить собственное будущее.\n\n"
                    . "⚙️ В *Wild World* всё взаимосвязано: каждое твоё действие влияет на остров — от простой добычи ресурсов и торговли до возведения высокотехнологичных сооружений.\n\n"
                    . "👀 *Совет:* Будь осторожен, ведь не все выжившие дружелюбны. Подумай, как защитить себя и свою базу.\n\n"
                    . "✨ *Твоя цель:* достичь вершин развития и влиять на весь остров — от ручного труда до автономных систем и нефтяных вышек. Возможно, тебя ждёт слава, а может и погибель... Решай сам!\n\n"
                    . "Если возникнут вопросы или захочешь обсудить стратегию — обязательно загляни в [наш общий чат](https://t.me/wild_world_info). Там помогут, подскажут и не дадут заскучать!\n\n"
                    . "А теперь — придумай своему герою имя и приступай к обучению. *Настало время показать, на что ты способен!* 🚀\n\n"
                    . "Удачи и смелости тебе, искатель приключений! Я — 🤖*Роби* — всегда готов помочь тебе взлететь к вершинам!";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🤔 Задать имя персонажа', 'callback_data' => 'setCharacterName']
                        ],
                    ]
                ];
                $encodedKeyboard = json_encode($keyboard);
            }

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
