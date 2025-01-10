<?php

namespace App\TaskHandlers\Events;

use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use App\Models\EventEffectsLogModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class FeverHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private $telegram;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->biomeModel = new BiomeModel();
        $this->eventModel = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->initTelegram();
    }

    private function initTelegram()
    {
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        $eventInfo = $this->eventModel->where('name_english', 'Fever')->first();
        if (!$eventInfo) {
            return; // Событие "Жаркая лихорадка" не найдено
        }

        // Проверяем, активно ли событие
        $isActiveEvent = $this->checkEventIsActive($eventInfo['event_id']);
        if (!$isActiveEvent) {
            return; // Событие не активно
        }

        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        $charactersInAffectedBiomes = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();

        foreach ($charactersInAffectedBiomes as $character) {
            $this->applyFeverEffects($character, $eventInfo);
        }
    }

    protected function checkEventIsActive($eventId)
    {
        $activeEventModel = new ActiveEventModel(); // Создаем экземпляр модели ActiveEventModel
        $activeEvent = $activeEventModel
            ->where('event_id', $eventId)
            ->where('status', 'active')
            ->first();

        return !empty($activeEvent);
    }

    protected function applyFeverEffects($character, $eventInfo)
    {
        if (!in_array($character['biome_id'], json_decode($eventInfo['biome_ids'], true))) {
            return; // Пропускаем персонажей, не находящихся в затронутых биомах
        }

        if (rand(1, 11) === 5) {
            // Специфическая логика применения эффектов события
            $debuffEffect = $this->calculateDebuffEffect($character, $eventInfo);
            if ($debuffEffect) {
                $this->logEventEffects($character['id'], $debuffEffect, $eventInfo['event_id']);
                $this->notifyCharacter($character, $debuffEffect);
            }
        }else{
            return;
        }
    }

    // Оставляем методы logEventEffects, notifyCharacter, и translateParameter как в EpidemicHandler, но убираем или изменяем специфические для "Эпидемии" методы

    protected function logEventEffects($characterId, $debuffEffect, $eventId)
    {
        $logModel = new EventEffectsLogModel();

        // Подготавливаем детали эффекта для записи в лог. Преобразуем массив эффектов в JSON строку.
        $effectDetails = json_encode($debuffEffect, JSON_UNESCAPED_UNICODE);

        // Подготавливаем данные для записи в таблицу логов
        $logData = [
            'character_id' => $characterId,
            'event_id' => $eventId,
            'effect_details' => $effectDetails,
            'event_time' => date('Y-m-d H:i:s'), // Фиксируем текущее время как время воздействия эффекта
        ];

        // Дополнительные данные, такие как номер ячейки и ID биома, можно добавить, если они нужны
        // Это предполагает, что у модели CharacterModel есть методы для получения этой информации
        $characterInfo = $this->characterModel->find($characterId);
        if ($characterInfo) {
            $logData['cell_number'] = $characterInfo['cell_number'] ?? null; // Если номер ячейки доступен
            $logData['biome_id'] = $characterInfo['biome_id'] ?? null; // Если ID биома доступен
        }

        // Сохраняем информацию о воздействии события на персонажа в таблицу логов
        $logModel->insert($logData);
    }

    protected function calculateDebuffEffect($character, $eventInfo)
    {
        $debuffEffects = [];

        // Получаем информацию о биоме персонажа
        $biome = $this->biomeModel->find($character['biome_id']);

        switch ($biome['biome_type']) {
            case 'wet':
                $healthDebuff = rand(5, 10); // Случайное уменьшение здоровья на 5-10%
                $newHealth = max(0.01, $character['health'] - $healthDebuff);
                $debuffEffects['health'] = $newHealth - $character['health']; // Фиксируем реальное изменение
                $character['health'] = $newHealth;
                break;

            case 'dry':
                $tirednessDebuff = rand(3, 7); // Случайное уменьшение выносливости на 3-7%
                $newTiredness = max(0.01, $character['tired'] - $tirednessDebuff);
                $debuffEffects['tired'] = $newTiredness - $character['tired']; // Фиксируем реальное изменение
                $character['tired'] = $newTiredness;
                break;

            default:
                $healthDebuff = rand(1, 3);
                $newHealth = max(0.01, $character['health'] - $healthDebuff);
                $debuffEffects['health'] = $newHealth - $character['health']; // Фиксируем реальное изменение
                $character['health'] = $newHealth;
                break;
        }

        // Обновляем параметры персонажа в базе данных
        $this->characterModel->update($character['id'], [
            'health' => $character['health'],
            'tired' => $character['tired'] ?? null // Обновляем только если было изменено
        ]);

        return $debuffEffects;
    }

    protected function notifyCharacter($character, $debuffEffect)
    {
        // Получаем Telegram ID пользователя, связанного с персонажем
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return; // Если пользователь не найден, выходим из метода
        }
        $chatId = $telegramUser['telegram_id'];

        // Формируем сообщение
        $message = "⚠️ *Внимание!* Вас коснулась *Жаркая лихорадка* 🌡️.\n\n";
        $message .= "🔥 Ваши параметры были изменены следующим образом:\n";

        // Добавляем детали изменений
        foreach ($debuffEffect as $param => $change) {
            $translatedParam = $this->translateParameter($param); // Предполагается, что метод translateParameter переводит название параметра и добавляет соответствующий эмоджи
            $message .= "{$translatedParam}: {$change}\n";
        }

        $message .= "\n💊 *Рекомендуем принять меры для восстановления.*";

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
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            // Можете отправить сообщение без изображения или с другим изображением
        }
    }

    protected function translateParameter($parameter)
    {
        // Здесь можно добавить логику перевода параметров и выбора соответствующих эмоджи
        $translations = [
            'experience' => 'Опыт',
            'health' => 'Здоровье',
            'strength' => 'Сила',
            'agility' => 'Ловкость',
            'intellect' => 'Интеллект',
            'tired' => 'Выносливость',
        ];

        return $translations[$parameter] ?? '❓ Неизвестный параметр';
    }

}
