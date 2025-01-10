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
        $message = "*🎉 Поздравляем!*\n\nВаш персонаж достиг уровня 10. Теперь вы можете выбрать фракцию, за которую будете играть.\n\n*⚠️ Выберите мудро! Сменить фракцию будет невозможно аж до смерти вашего персонажа.*\n\n*Фракции с PVP режимом:*\n1. 🛡️ *Милитари* - Специальная фракция с упором на военное развитие.\n2. 🌲 *Партизаны* - Фракция с упором на скрытные операции, саботаж и партизанскую войну.\n\n*Фракции с PVE режимом:*\n3. 🛠️ *Инженеры* - Фракция с упором на постройку роботов и лабораторий.\n4. 🌾 *Фермеры* - Фракция с упором на производство продуктов питания.\n\n🔽 *Нажатие на кнопку ниже не выберет фракцию, а только предоставит подробное описание о каждой из них.*";

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
                'description' => "Это фракция, специализирующаяся на военном развитии. Игроки, выбравшие эту фракцию, могут строить и управлять военными базами, оснащать свои отряды передовым оружием и техникой. Их цель — защитить свои территории и расширить влияние путем захвата ключевых точек.",
                'advantages' => "👉🏻Доступ к мощному вооружению и технике\n👉🏻Возможность захватывать и удерживать ключевые точки\n👉🏻Постройки военных баз",
                'disadvantages' => "👉🏻Не может причинять урон PVE-фракциям\n👉🏻Очень зависит от ресурсов пищи и электроэнергии",
            ],
            'Partisans' => [
                'title' => '🌲 Партизаны',
                'description' => "Мастера скрытных операций и саботажа. Их цель — подрывать силы противников изнутри, устраивать засады и избегать прямых столкновений. Партизаны действуют в тени, используя свои навыки для создания хаоса в рядах врагов.",
                'advantages' => "👉🏻Высокая скрытность и мобильность\n👉🏻Способность устраивать засады и саботаж\n👉🏻Легко скрываться и уклоняться от боев",
                'disadvantages' => "👉🏻Ограниченный доступ к тяжелому вооружению\n👉🏻Зависимость от скрытных операций для успеха",
            ],
            'Engineers' => [
                'title' => '🛠️ Инженеры',
                'description' => 'Фракция, специализирующаяся на высоких технологиях и автоматизации. Они создают роботов и сложные электронные системы, которые могут существенно влиять на игровой мир.',
                'advantages' => "👉🏻Способность к созданию и продаже роботов\n👉🏻Доступ к уникальному крафту по электронике\n👉🏻Возможность влиять на мир",
                'disadvantages' => "👉🏻Ограниченная зона обитания\n👉🏻Не могут участвовать в PVP",
            ],
            'Farmers' => [
                'title' => '🌾 Фермеры',
                'description' => 'Мастера агрокультуры, они сосредоточены на производстве продуктов питания и лекарственных растений. Их влияние распространяется на экономику игры через поставки продовольствия.',
                'advantages' => "👉🏻Расширенное фермерство и производство пищи\n👉🏻Влияние на мировую экономику и количество ресурсов\n👉🏻Безопасный режим",
                'disadvantages' => "👉🏻Ограниченная зона обитания\n👉🏻Не могут участвовать в PVP",
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
            'Military' => 1,
            'Partisans' => 2,
            'Engineers' => 3,
            'Farmers' => 4,
        ];

        if (!isset($factionIds[$faction])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Неверная фракция.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterFactionModel = new CharacterFactionModel();
        $factionModel = new FactionModel();

        $factionId = $factionIds[$faction];
        $currentTime = date('Y-m-d H:i:s');

        // Проверяем, не выбрана ли уже фракция
        $existingFaction = $characterFactionModel->where('character_id', $character['id'])->first();

        if ($existingFaction && !empty($existingFaction['joined_at']) && $existingFaction['notification_status'] === 'True') {
            $message = "Ахах, думал самый умный, не выйдет!\n\n" .
                "Фракция уже выбрана и будешь ты в ней до самой своей смерти. Удачи, братишка, и до встречи!";

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);
        }

        $data = [
            'faction_id' => $factionId,
            'joined_at' => $currentTime,
            'notification_status' => 'True',
        ];

        // Обновляем или вставляем запись в таблице character_factions
        if ($characterFactionModel->where('character_id', $character['id'])->countAllResults() > 0) {
            $characterFactionModel->where('character_id', $character['id'])->set($data)->update();
        } else {
            $data['character_id'] = $character['id'];
            $characterFactionModel->insert($data);
        }

        // Получаем данные фракции для уведомления
        $factionDetails = $this->getFactionDetails($faction);

        // Формируем сообщение о присоединении к фракции
        $message = "*🎉 Поздравляем!*\n\nВы выбрали фракцию: {$factionDetails['title']}\n\n" .
            "Вы больше не сможете изменить фракцию до тех пор, пока персонаж не погибнет или не произойдет вайп.\n\n" .
            "*Описание фракции:*\n{$factionDetails['description']}\n\n" .
            "*➕ Преимущества:*\n{$factionDetails['advantages']}\n\n" .
            "*➖ Недостатки:*\n{$factionDetails['disadvantages']}";

        // Клавиатура для дальнейших действий
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                ],
                [
                    ['text' => '🏕️ Окопаться', 'callback_data' => 'entrench'],
                    ['text' => '📜 Квесты и задания', 'callback_data' => 'questAndTask'],
                ],
                [
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                    ['text' => '⛏️ Добыть ресурсы', 'callback_data' => 'gather'],
                ],
            ],
        ];

        // Отправляем ответ пользователю
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}