<?php

namespace App\TaskHandlers\Events;

use App\Models\ActiveEventModel;
use App\Models\EventModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use App\Models\TelegramUserModel;

class SpringFloodHandler {
    protected $activeEventModel;
    protected $eventModel;
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct() {
        $this->activeEventModel = new ActiveEventModel();
        $this->eventModel = new EventModel();
        $this->characterModel = new CharacterModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel = new TaskModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process() {
        $eventInfo = $this->eventModel->where('name_english', 'SpringFlood')->first();
        if (!$eventInfo) {
            return; // Событие "SpringFlood" не найдено
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$activeEvent) {
            return; // Активное событие "SpringFlood" не найдено
        }

        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();
        $message = '';

        foreach ($characters as $character) {
            $tasksInWork = $this->characterTaskModel
                ->where('character_id', $character['id'])
                ->where('status', 'in_work')
                ->findAll();

            foreach ($tasksInWork as $taskInWork) {
                $task = $this->taskModel->find($taskInWork['task_id']);
                if (in_array($task['name'], ['Gather', 'ExploreTheArea'])) {
                    // Проверяем уровень здоровья персонажа
                    if ($character['health'] <= 0.1) {
                        continue; // Если здоровье <= 0.1, пропускаем понижение здоровья
                    }

                    $damage = rand(1, $eventInfo['effect_value']);
                    $levelCoefficient = max(1, min(999, $character['level']) / 100);
                    $finalDamage = round($damage / $levelCoefficient);

                    if (rand(1, 5) === 1) {
                        $newHealth = max(0, $character['health'] - $finalDamage);
                        $this->characterModel->update($character['id'], ['health' => $newHealth]);
                        // Добавить уведомление пользователя о полученном уроне
                        $message .= "⚠️ *Внимание*, во время события: ➡️ *Весенний паводок.*\n\n";
                        $message .= "ℹ️ *Вы потеряли:*\n";
                        $message .= "💖 *{$finalDamage}* _Единиц здоровья_\n\n";
                        $message .= "💖 *Сейчас у вас: {$newHealth}* _Единиц здоровья_\n\n";
                        $message .= "_Вам нужно вернуться на базу или использовать аптечку во избежание смерти!_\n\n";
                        $message .= "Посмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения";

                        $imagePath = base_url('uploads/telegram/flooded_areas_by_the_river.jpg'); // Укажите актуальный путь к изображению
                        $chatId = $this->telegramUserModel->where('id',  $character['telegram_user_id'])->first()['telegram_id'];

                        $this->sendMessageToUsers($message, $imagePath, $chatId);
                    }
                }
            }
        }
    }

    protected function sendMessageToUsers($message, $imagePath, $chatId) {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            return Request::sendPhoto([
                'chat_id' => $chatId,
                'photo' => Request::encodeFile($imagePath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            // Можете отправить сообщение без изображения или с другим изображением
        }
    }
}
