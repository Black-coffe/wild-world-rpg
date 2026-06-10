<?php

namespace App\Controllers\Telegram\Commands;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use Longman\TelegramBot\Commands\UserCommand;
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

        // 1. Закрепляем постоянную клавиатуру.
        // ADR-103 Часть A: единый источник истины — BotMenuService::mainReplyKeyboard()
        // (раньше определение жило только здесь).
        $replyKeyboard = \App\Services\Telegram\BotMenuService::mainReplyKeyboard();

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
            $starterKitText = null; // ADR-104: текст набора Роби (если выдан)

            if (!empty($spawnCells)) {
                $randomCell = $spawnCells[array_rand($spawnCells)];
                $cellNumber = $randomCell['cell_number'];

                // Обновляем персонажу cell_number
                $characterModel->update($createdCharacterId, ['cell_number' => $cellNumber]);

                // ADR-103 Слой 2: назначаем новичку обучающую цепочку «Первые шаги
                // выжившего» (dormant под onboarding.quest_chain.enabled). Идемпотентно.
                (new \App\Services\Onboarding\OnboardingChainService())
                    ->ensureChainAssigned(['id' => (int) $createdCharacterId, 'level' => 1]);

                // ADR-104 Фаза 1: стартовый набор выжившего (dormant под
                // onboarding.starter_kit.enabled). grant() видаёт паёк + idempotency-флаг
                // и возвращает текст Роби (или null, если набор выключен/уже выдан).
                $starterKitText = (new \App\Services\Onboarding\StarterKitService())
                    ->grant((int) $createdCharacterId, (int) $createdUserId, (int) $chatId);

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

            // ADR-104 Фаза 1: если стартовый набор выдан — шлём сообщение Роби о пайке
            // ПЕРЕД финальным welcome'ом, чтобы CTA «Задать имя» остался последним
            // (самым заметным). MEDIA-OFF: текстовое, весь смысл в тексте.
            if ($starterKitText !== null) {
                Request::sendMessage([
                    'chat_id'                  => $chatId,
                    'text'                     => $starterKitText,
                    'parse_mode'               => 'Markdown',
                    'disable_web_page_preview' => true,
                ]);
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
            // Если персонаж УЖЕ существует → используем CharacterService.
            //
            // ADR-039 N4 пересмотрен (2026-06-01, после ADR-087 вайпа): /start ВСЕГДА
            // (пере)отправляет постоянную reply-клавиатуру. Раньше для существующих
            // игроков она НЕ слалась (полагались на то, что она уже стоит на клиенте),
            // но игрок, потерявший её (почистил чат / после вайпа), не мог вернуть меню
            // через /start. Карточка персонажа несёт inline-keyboard, а reply+inline
            // на одном сообщении Telegram не совмещает → шлём отдельным лёгким сообщением
            // ПЕРЕД карточкой. Пост-вайп рассылка зовёт «напиши /start» — меню обязано
            // гарантированно вернуться.
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => '🧭 Меню ниже.',
                'reply_markup' => $replyKeyboard,
            ]);

            // ADR-103 Слой 2: добираем обучающую цепочку существующим новичкам (level ≤
            // max). Идемпотентно — повторный /start не дублирует и не трогает ветеранов.
            // returnType модели = CharacterEntity; instanceof сужает union для PHPStan.
            if ($existingCharacter instanceof \App\Entities\CharacterEntity) {
                (new \App\Services\Onboarding\OnboardingChainService())
                    ->ensureChainAssigned($existingCharacter);
            }

            $charService = new CharacterService();
            return $charService->showCharacterInfo($chatId, $existingCharacter);
        }
    }
}
