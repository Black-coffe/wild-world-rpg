<?php

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use App\Models\CharacterModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;

class ChooseFaction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();
        $callbackParts = explode('_', $callbackData);

        if ($callbackParts && $callbackParts[1] == 'info') {
            return $this->sendFactionInfo($chatId);
        }

        $faction = $callbackParts[1];
        $characterModel = new CharacterModel();
        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (isset($callbackParts[2]) && $callbackParts[1] == 'joinFaction') {
            return $this->fractionation($chatId, $character, $callbackParts[2]);
        }

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $factionDetails = $this->getFactionDetails($faction);
        $messageText = "*{$factionDetails['title']}*\n\n{$factionDetails['description']}\n\n*➕ Преимущества:*\n{$factionDetails['advantages']}\n\n*➖ Недостатки:*\n{$factionDetails['disadvantages']}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Вступить', 'callback_data' => "chooseFaction_joinFaction_{$faction}"],
                    ['text' => 'Фракции', 'callback_data' => 'chooseFaction_info'],
                    ['text' => 'Персонаж', 'callback_data' => 'character'],
                ],
            ],
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function sendFactionInfo($chatId)
    {
        $message = "*🎉 Поздравляем!*  

Ваш персонаж достиг уровня 10. Теперь вы можете выбрать фракцию, за которую будете играть.  

*⚠️ Выберите мудро!* Сменить фракцию будет невозможно вплоть до вайпа вашего персонажа.  

*Учтите:* Все фракции являются PVP, но сражения доступны только за пределами стартовой зоны респавна игроков.  

1. 🛡️ *Милитари* — специализируются на военных технологиях и прямом столкновении.  
2. 🌲 *Партизаны* — делают ставку на скрытность, саботаж и партизанскую войну.  
3. 🛠️ *Инженеры* — развивают робототехнику и лаборатории для стратегического превосходства.  
4. 🌾 *Фермеры* — акцент на производство ресурсов и продовольствия, однако при необходимости готовы вступить в бой.  

🔽 *Нажатие на кнопку ниже не совершит окончательный выбор, а лишь покажет подробное описание каждой фракции.*";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡️ Милитари', 'callback_data' => 'chooseFaction_Military'],
                    ['text' => '🌲 Партизаны', 'callback_data' => 'chooseFaction_Partisans'],
                ],
                [
                    ['text' => '🛠️ Инженеры', 'callback_data' => 'chooseFaction_Engineers'],
                    ['text' => '🌾 Фермеры', 'callback_data' => 'chooseFaction_Farmers'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function getFactionDetails($faction)
    {
        $factionDetails = [
            'Military' => [
                'title' => '🛡️ Милитари',
                'description' => "Фракция, специализирующаяся на военном развитии. Игроки, выбравшие этот путь, могут строить и укреплять военные базы, оснащать свои отряды передовым вооружением и техникой. Их главная цель — защитить свои территории и расширять влияние, захватывая ключевые точки на карте.",
                'advantages' => "👉🏻Доступ к мощному вооружению и технике\n👉🏻Возможность захватывать и удерживать ключевые точки\n👉🏻Военные базы с укреплённой обороной",
                'disadvantages' => "👉🏻Высокий расход ресурсов на вооружение\n👉🏻Серьёзная зависимость от логистики (пища, электроэнергия)",
            ],
            'Partisans' => [
                'title' => '🌲 Партизаны',
                'description' => "Мастера скрытных операций и саботажа. Их стратегия — действовать из тени, устраивать засады и подрывать силы противников, избегая прямых столкновений, когда это невыгодно. Враг никогда не знает, откуда придёт удар.",
                'advantages' => "👉🏻Высокая скрытность и мобильность\n👉🏻Умение устраивать засады и наносить внезапные удары\n👉🏻Лёгкость в уходе от преследования",
                'disadvantages' => "👉🏻Ограниченный доступ к тяжёлому вооружению\n👉🏻Зависимость от неожиданности и тактического преимущества",
            ],
            'Engineers' => [
                'title' => '🛠️ Инженеры',
                'description' => 'Фракция, сфокусированная на высоких технологиях и автоматизации. Инженеры могут строить роботов, разрабатывать сложные электронные системы и использовать их для защиты базы или атаки врагов.',
                'advantages' => "👉🏻Создание и модификация роботов\n👉🏻Уникальные виды крафта и электроники\n👉🏻Возможность влиять на мир через технологические проекты",
                'disadvantages' => "👉🏻Высокая требовательность к редким ресурсам\n👉🏻Зависимость от энергии и научных разработок",
            ],
            'Farmers' => [
                'title' => '🌾 Фермеры',
                'description' => 'Специалисты по агрокультуре и продовольствию. Они обеспечивают мир продуктами питания и лекарственными растениями, формируя мощную экономическую базу для себя и союзников. Однако, при необходимости, тоже участвуют в PvP и способны оборонять свои владения.',
                'advantages' => "👉🏻Расширенное фермерство и производство пищи\n👉🏻Сильное влияние на экономику игры\n👉🏻Способность поддерживать союзников припасами",
                'disadvantages' => "👉🏻Низкая боевая специализация\n👉🏻Возможная уязвимость при недостаточной защите",
            ],
        ];

        return $factionDetails[$faction] ?? [
            'title' => 'Неизвестная фракция',
            'description' => 'Описание этой фракции недоступно.',
            'advantages' => 'Преимущества неизвестны.',
            'disadvantages' => 'Недостатки неизвестны.',
        ];
    }

    protected function fractionation($chatId, $character, $faction)
    {
        $factionIds = [
            'Military'   => 1,
            'Partisans'  => 2,
            'Engineers'  => 3,
            'Farmers'    => 4,
        ];

        if (!isset($factionIds[$faction])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Неверная фракция.',
            ]);
        }

        $factionId = $factionIds[$faction];
        $currentTime = date('Y-m-d H:i:s');

        $characterFactionModel = new CharacterFactionModel();
        $factionModel          = new FactionModel();

        // Узнаём, есть ли уже запись (обычно должна быть с faction_id=5, если ещё не выбрал)
        $existingFaction = $characterFactionModel
            ->where('character_id', $character['id'])
            ->first();

        // Если персонаж УЖЕ выбрал фракцию (joined_at не null, notification_status='True'), запрещаем
        if ($existingFaction
            && !empty($existingFaction['joined_at'])
            && $existingFaction['notification_status'] === 'True'
            && (int)$existingFaction['faction_id'] !== 5
        ) {
            $message = "Ахах, думал самый умный, не выйдет!\n\n"
                . "Фракция уже выбрана... (и т.д.)";

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);
        }

        // Если это первая запись (или есть запись, но там faction_id=5), обновляем.
        // Помечаем, что фракция выбрана окончательно
        $data = [
            'faction_id'          => $factionId,
            'joined_at'           => $currentTime,
            'notification_status' => 'True',
            'notified_at'         => $currentTime, // можно обновить, чтоб не слало напоминания
        ];

        if ($existingFaction) {
            // update
            $characterFactionModel
                ->where('id', $existingFaction['id'])
                ->set($data)
                ->update();
        } else {
            // insert (если на момент вызова записи вообще не было)
            $data['character_id'] = $character['id'];
            $characterFactionModel->insert($data);
        }

        // Получаем данные о новой фракции
        $factionDetails = $this->getFactionDetails($faction);
        $message = "*🎉 Поздравляем!*\n\n"
            . "Вы выбрали фракцию: {$factionDetails['title']}\n\n"
            . "*Описание:*\n{$factionDetails['description']}\n\n"
            . "*➕ Преимущества:*\n{$factionDetails['advantages']}\n\n"
            . "*➖ Недостатки:*\n{$factionDetails['disadvantages']}";

        // Кнопки после выбора
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🚜 Переехать',  'callback_data' => 'move'],
                ],
                [
                    ['text' => '🏕️ Окопаться',     'callback_data' => 'entrench'],
                    ['text' => '📜 Квесты/задания', 'callback_data' => 'questAndTask'],
                ],
            ],
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}