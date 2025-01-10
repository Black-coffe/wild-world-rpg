<?php

namespace App\TaskHandlers\Other;

use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Libraries\TelegramMessages;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class FactionNotificationHandler
{
    protected $characterModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    protected $telegramMessages;
    private $telegram;

    public function __construct()
    {
        $this->telegramMessages = new TelegramMessages();
        $this->characterModel = new CharacterModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->telegramUserModel = new TelegramUserModel();
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        // Получаем всех персонажей с уровнем 10 и выше, у которых нет записи в таблице character_factions
        $characters = $this->characterModel->where('level >=', 10)->findAll();

        foreach ($characters as $character) {
            // Проверяем, есть ли запись в character_factions для этого персонажа
            $factionEntry = $this->characterFactionModel->where('character_id', $character['id'])->first();

            if (!$factionEntry) {
                // Если записи нет, отправляем уведомление и записываем информацию
                $this->sendFactionNotification($character);
            } else {
                // Если запись есть, проверяем статус уведомления
                $notifiedAt = strtotime($factionEntry['notified_at']);
                $currentTime = time();
                // $notificationInterval = 24 * 60 * 60; // 24 часа
                $notificationInterval = 24;

                if ($factionEntry['notification_status'] === 'False' && ($currentTime - $notifiedAt) >= $notificationInterval) {
                    // Если уведомление не было выбрано и прошло более 24 часов, отправляем повторное уведомление
                    $this->sendFactionNotification($character, $factionEntry);
                }
            }
        }
    }

    protected function sendFactionNotification($character, $factionEntry = null)
    {
        $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];

        if ($factionEntry) {
            // Обновляем запись, если она существует
            $this->characterFactionModel->update($factionEntry['id'], [
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_count' => $factionEntry['notification_count'] + 1
            ]);
        } else {
            // Создаем новую запись, если её не было
            $this->characterFactionModel->insert([
                'character_id' => $character['id'],
                'faction_id' => 1, // Пока фракция не выбрана
                'joined_at' => null, // Пока не вступил в фракцию
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_status' => 'False',
                'notification_count' => 1
            ]);
        }

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

        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        return Request::sendMessage([
            'chat_id' => $telegramId,
            'text'    => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
