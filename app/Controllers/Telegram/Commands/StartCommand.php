<?php

namespace App\Controllers\Telegram\Commands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\TelegramUserModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use DateTime;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();
        $from = $message->getFrom();
        $telegramId = $from->getId();
        $username = $from->getUsername();
        $firstName = $from->getFirstName();
        $lastName = $from->getLastName();

        $telegramUserModel = new TelegramUserModel();
        $existingUser = $telegramUserModel->where('telegram_id', $telegramId)->first();

        if (!$existingUser) {
            $createdUserId = $telegramUserModel->insert([
                'telegram_id' => $telegramId,
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], true);
        } else {
            $createdUserId = $existingUser['id'];
        }

        $characterModel = new CharacterModel();
        $existingCharacter = $characterModel->where('telegram_user_id', $createdUserId)->first();

        if (!$existingCharacter) {
            $createdCharacterId = $characterModel->insert([
                'telegram_user_id' => $createdUserId,
                'name' => $username ?: 'Unknown Hero',
                'level' => 1,
                'experience' => 0.01,
                'health' => 100,
                'tired' => 100,
                'strength' => 0.01,
                'agility' => 0.01,
                'intellect' => 0.01,
                'gold' => 100,
                'cell_number' => null,
            ], true);

            $mapModel = new MapModel();
            $biomeModel = new BiomeModel();

            $allowedBiomes = [1, 2, 3, 5, 6, 7, 8, 9];
            $spawnCells = $mapModel->where('coordinate_y >=', 900)->whereIn('biome_id', $allowedBiomes)->findAll();

            if (!empty($spawnCells)) {
                $randomCell = $spawnCells[array_rand($spawnCells)];
                $cellNumber = $randomCell['cell_number'];

                $characterModel->update($createdCharacterId, ['cell_number' => $cellNumber]);

                $biome = $biomeModel->find($randomCell['biome_id']);

                $text = "🎉 *Добро пожаловать в Wild World!* 🌍\n\n"
                    . "✨ _Приветствую тебя, путник! Меня зовут_ 🤖*Роби*, _и я буду твоим проводником в этом захватывающем мире._\n"
                    . "_Мы на неизведанном острове в постапокалиптическом мире, где после глобальной катастрофы выжили лишь немногие._\n"
                    . "_Эти земли полны опасностей и возможностей. Здесь выжившие могут быть как мирными, так и агрессивно настроенными. Твоя задача — не просто выжить, а стать влиятельной личностью на острове!_\n\n"
                    . "🚀 *В Wild World* тебя ждут 9 уникальных биомов, каждый со своим уровнем опасности и сложностью выживания. Сейчас ты начинаешь с нуля, с одними лишь голыми руками, но приложив усилия и проявив настойчивость, сможешь достичь уровня, когда перед тобой откроются возможности автоматизации, колонизации и строительства дронов или роботов.\n\n"
                    . "🍀 Здесь тебе нужны постоянство, удача и расчетливый ум. Каждое твое действие влияет на весь остров — будь то добыча ресурсов, торговля или строительство нефтяных вышек. Все взаимосвязано, и у тебя есть шанс возглавить эту цепь или оказаться её нижним звеном. Все в твоих руках!\n\n"
                    . "🛡️ Я, твой наставник и помощник, всегда буду рядом, чтобы подсказать и дать совет. Твой друг — 🤖*Роби!*\n\n"
                    . "*Ну а теперь придумай своему герою имя и начни путь к славе и величию!* 🚀\n";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🤔 Задать имя персонажа', 'callback_data' => 'setCharacterName']
                        ],
                    ]
                ];
                $encodedKeyboard = json_encode($keyboard);

            } else {
                $text = "Извините, произошла ошибка при попытке определить локацию для спавна. Пожалуйста, попробуйте ещё раз.";
            }
        } else {
            // Existing character info
            $registrationDate = new DateTime($existingCharacter['created_at']);
            $daysSpent = $registrationDate->diff(new DateTime('now'))->days;

            $text = "👤 *Персонаж ID*: {$existingCharacter['id']}\n"
                . "📅 Дата регистрации: {$registrationDate->format('Y-m-d')}\n"
                . "🕰 Проведено в игре дней: *{$daysSpent}*\n"
                . "📈 *Уровень:* {$existingCharacter['level']}\n🌟 *Опыт:* {$existingCharacter['experience']}\n"
                . "🤸‍♂️ *Ловкость:* {$existingCharacter['agility']}\n🧠 *Интеллект:* {$existingCharacter['intellect']}\n"
                . "💖 *Здоровье:* {$existingCharacter['health']}\n💪 *Сила:* {$existingCharacter['strength']}\n"
                . "🥱 *Выносливость:* {$existingCharacter['tired']}\n"
                . "💰 *В твоем сундуке:* " . number_format($existingCharacter['gold']) . " монет\n\n"
                . "*Готов к новым подвигам? 🔥*\n\n"
                . "P.S. Не забудь проверить обновления в квестах 📜\n\n"
                . "*P.S.* Обязательно прочти о [последних обновлениях в игре](https://wildworld.fun/devblog/) 🌎\n\n";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ],
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                        ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                    ],
                    [
                        ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                    ]
                ]
            ];
            $encodedKeyboard = json_encode($keyboard);

        }

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup' => $encodedKeyboard
        ]);
    }

}
