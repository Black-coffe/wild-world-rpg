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
        // Меняем условие с 2% на ~15%
        // Если бросок > 15, значит событие не срабатывает (пропуск).
        if (mt_rand(1, 100) > 15) {
            return; // ~85% случаев событие не обрабатывается
        }

        // Получаем информацию о событии
        $eventInfo = $this->eventModel->where('name_english', 'GeothermalFountains')->first();
        if (!$eventInfo) {
            log_message('error', 'Geothermal Springs event not found.');
            return;
        }

        // Проверяем, активно ли событие в active_events
        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            log_message('error', 'Geothermal Springs event is not active.');
            return;
        }

        // Получаем, в каких биомах действует событие
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            log_message('error', 'Invalid biome_ids data for Geothermal Springs event.');
            return;
        }

        // Ищем всех персонажей, кто находится в этих биомах
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        // Применяем эффект к каждому
        foreach ($characters as $character) {
            $this->applyHealing($character);
        }
    }

    protected function applyHealing($character)
    {
        $healAmount = rand(1, 10); // Значение исцеления
        // Случайный выбор: восстанавливаем либо здоровье (health), либо выносливость (tired)
        $healedProperty = rand(0, 1) ? 'health' : 'tired';

        // Не превышаем 100
        if ($character[$healedProperty] + $healAmount > 100) {
            $healAmount = 100 - $character[$healedProperty];
        }

        // Обновляем в БД
        $this->characterModel->update($character['id'], [
            $healedProperty => $character[$healedProperty] + $healAmount
        ]);

        // Уведомляем пользователя
        $this->notifyCharacter($character, $healedProperty, $healAmount);
    }

    protected function notifyCharacter($character, $boostType, $boostValue)
    {
        $telegramUserId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUserId) {
            return;
        }

        $chatId = $telegramUserId['telegram_id'];
        $boostTypeText = $boostType === 'health' ? 'здоровье' : 'выносливость';

        $message = sprintf(
            "🌋 *Благодаря событию 'Геотермальные фонтаны' ваш персонаж восстановил %s на %d единиц.*\n\n" .
            "_Наслаждайтесь природным источником здоровья и энергии..._",
            $boostTypeText,
            $boostValue
        );

        $message .= "\n\nПосмотрите сколько времени ещё будет данное событие, чтобы принять стратегические решения 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        $photo = base_url('uploads/telegram/geothermal__fountains.png'); // Укажите реальный путь к файлу

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
