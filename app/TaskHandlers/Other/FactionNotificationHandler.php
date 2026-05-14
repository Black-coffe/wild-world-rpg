<?php

namespace App\TaskHandlers\Other;

use App\Attributes\HandlerKey;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Libraries\TelegramMessages;
use App\TaskHandlers\BaseTaskHandler;

/**
 * v0.51.42 (F2.9 batch-7 final): extends BaseTaskHandler. Drop manual Telegram
 * init. process() → handle(array $task = []): void. Drop **broken** `Request::
 * answerCallbackQuery(['callback_query_id' => $telegramId])` (chat_id passed
 * as callback_query_id — fires daily silently без value у cron context).
 * Request::sendMessage → safeSendMessage.
 */
#[HandlerKey(
    key: 'faction_notification',
    displayName: 'Уведомления о фракциях',
    description: 'Recurring (Tasks.php every minute): уведомляет персонажей о возможности выбрать/сменить фракцию при достижении условий.',
)]
class FactionNotificationHandler extends BaseTaskHandler
{
    protected $characterModel;
    protected $characterFactionModel;
    protected $telegramUserModel;
    protected $telegramMessages;

    public function __construct()
    {
        $this->telegramMessages = new TelegramMessages();
        $this->characterModel = new CharacterModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->telegramUserModel = new TelegramUserModel();
    }

    /**
     * @param array<string,mixed> $task TaskHandlerInterface signature.
     */
    public function handle(array $task = []): void
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
                $notificationInterval = 24 * 60 * 60; // 24 часа

                if ($factionEntry['notification_status'] === 'False' && ($currentTime - $notifiedAt) >= $notificationInterval) {
                    // Если уведомление не было выбрано и прошло более 24 часов, отправляем повторное уведомление
                    $this->sendFactionNotification($character, $factionEntry);
                }
            }
        }
    }

    public function sendFactionNotification($character, $factionEntry = null): void
    {
        $telegramId = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first()['telegram_id'];

        // Если запись о фракции уже есть (но notified_at нужно обновить)
        if ($factionEntry) {
            $this->characterFactionModel->update($factionEntry['id'], [
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_count' => $factionEntry['notification_count'] + 1
            ]);
        } else {
            // ИНАЧЕ создаём запись з faction_id=5 (нейтральная фракция)
            $this->characterFactionModel->insert([
                'character_id' => $character['id'],
                'faction_id' => 5,
                'joined_at' => null,
                'notified_at' => date('Y-m-d H:i:s'),
                'notification_status' => 'False',
                'notification_count' => 1
            ]);
        }

        $message = "*\xF0\x9F\x8E\x89 Поздравляем!*\n\n"
            . "Ваш персонаж достиг уровня 10. Теперь вы можете выбрать фракцию... (и т.д.)";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡️ Милитари',  'callback_data' => 'chooseFaction_Military'],
                    ['text' => '🌲 Партизаны', 'callback_data' => 'chooseFaction_Partisans'],
                ],
                [
                    ['text' => '🛠️ Инженеры',  'callback_data' => 'chooseFaction_Engineers'],
                    ['text' => '🌾 Фермеры',   'callback_data' => 'chooseFaction_Farmers'],
                ],
            ]
        ];

        $this->safeSendMessage(
            $telegramId,
            $message,
            ['parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)]
        );
    }
}
