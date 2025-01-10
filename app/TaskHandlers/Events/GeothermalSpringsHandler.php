<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class GeothermalSpringsHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        if (mt_rand(0, 100) >= 2) {
            return; // 98% шанс на то, что событие не будет обработано
        }

        $eventInfo = $this->eventModel->where('name_english', 'GeothermalFountains')->first();
        if (!$eventInfo) {
            log_message('error', 'Geothermal Springs event not found.');
            return; // Если событие не найдено, останавливаем выполнение
        }

        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            log_message('error', 'Geothermal Springs event is not active.');
            return; // Если событие не активно, останавливаем выполнение
        }

        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        foreach ($characters as $character) {
            $this->applyHealing($character);
        }
    }

    protected function applyHealing($character)
    {
        $healAmount = rand(1, 10); // Значение исцеления
        $healedProperty = rand(0, 1) ? 'health' : 'tired'; // Выбор свойства для исцеления

        // Гарантируем, что здоровье и усталость не превысят максимально допустимые значения
        if ($character[$healedProperty] + $healAmount > 100) {
            $healAmount = 100 - $character[$healedProperty];
        }

        // Применяем исцеление
        $this->characterModel->update($character['id'], [$healedProperty => $character[$healedProperty] + $healAmount]);

        // Отправляем уведомление персонажу
        $this->notifyCharacter($character, $healedProperty, $healAmount);
    }

    protected function notifyCharacter($character, $boostType, $boostValue)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return; // Если Telegram пользователя не найдено, прекращаем выполнение
        }

        $chatId = $telegramUserId['telegram_id'];
        $boostTypeText = $boostType === 'health' ? 'здоровье' : 'выносливость';
        $message = sprintf(
            "🌋 *Благодаря событию 'Геотермальные фонтаны' ваш персонаж восстановил %s на %d единиц.*\n\n" .
            "_Наслаждайтесь природным источником здоровья и энергии..._",
            $boostTypeText,
            $boostValue
        );
        $message .= "\n\nПосмотрите сколько времени еще будет данное событие, чтобы принять стратегические решения 👇";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];
        // Путь к изображению для лесного пожара
        $photo = base_url('uploads/telegram/geothermal__fountains.png'); // Необходимо указать реальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo' => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения: " . $e->getMessage());
        }
    }
}
