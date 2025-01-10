<?php

namespace App\TaskHandlers\Events;

use DateTime; // Добавляем эту строку
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\TelegramUserModel;

class VolcanicEruptionHandler extends Controller
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
        if (!$this->isEventActive('volcanic_eruption')) {
            return; // Если событие не активно, прекращаем выполнение
        }

        $biomeId = $this->getBiomeIdByName('Вулканические территории');
        if (!$biomeId) {
            return; // Если ID биома не найден
        }

        if (!mt_rand(0, 100) < 3) {
            return; // Рандомное влияние события на игрока
        }

        $charactersInBiome = $this->characterModel->where('biome_id', $biomeId)->findAll();
        foreach ($charactersInBiome as $character) {
            $this->applyVolcanicEruptionEffects($character);
        }
    }

    protected function isEventActive($eventNameEnglish)
    {
        $event = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$event) {
            return false;
        }

        $isActive = $this->activeEventModel
            ->where('event_id', $event['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($isActive);
    }

    protected function getBiomeIdByName($biomeName)
    {
        $biome = $this->biomeModel->where('name', $biomeName)->first();
        return $biome ? $biome['id'] : null;
    }

    protected function applyVolcanicEruptionEffects($character)
    {
        // Проверяем, что текущее здоровье персонажа больше 0.01
        if ($character['health'] <= 0.01) {
            return; // Если здоровье меньше или равно 0.01, прекращаем дальнейшую обработку
        }

        $event = $this->eventModel->where('name_english', 'volcanic_eruption')->first();
        if (!$event) {
            return;
        }

        $isActiveEvent = $this->activeEventModel
            ->where('event_id', $event['event_id'])
            ->where('status', 'active')
            ->first();

        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return;
        }

        $effectValue = $event['effect_value'];
        $damage = $this->calculateDamage($biome, $character['level'], $effectValue);

        // Применяем урон к здоровью персонажа с учетом минимального порога в 0.01
        $newHealth = max(0.01, $character['health'] - $damage);
        $this->characterModel->update($character['id'], ['health' => $newHealth]);

        // Определяем время окончания события
        $endTime = $this->formatEventEndTime($isActiveEvent);

        // Отправляем уведомление персонажу
        $this->notifyCharacterAboutVolcanicEruption($character, $damage, $endTime);
    }

    protected function calculateDamage($biome, $characterLevel, $effectValue) {
        $dangerLevel = $biome['danger_level']; // От 1 до 10
        $survivalDifficulty = $biome['survival_difficulty']; // От 1 до 10
        $effectMultiplier = $effectValue / 100; // Преобразуем в множитель от 0 до 1

        // Рассчитываем фактор уровня персонажа так, чтобы с увеличением уровня урон уменьшался
        $levelFactor = 1 / (1 + log($characterLevel + 1));

        // Интегрируем факторы опасности и сложности биома
        $biomeFactor = ($dangerLevel + $survivalDifficulty) / 20; // Суммарный фактор биома делится на 20 для умеренного влияния

        // Основная формула расчёта урона
        $damage = 10 * $effectMultiplier * $biomeFactor * $levelFactor; // Базовое значение урона умножается на все факторы

        // Внедряем случайный элемент в расчёт урона для добавления непредсказуемости
        $randomFactor = rand(50, 150) / 100; // Случайное колебание в пределах +/- 50%
        $damage *= $randomFactor;

        // Округляем урон до двух десятичных знаков
        return round($damage, 2);
    }

    protected function formatEventEndTime($isActiveEvent)
    {
        $endDateTime = new DateTime($isActiveEvent['end_time']);
        $currentDateTime = new DateTime();
        $interval = $currentDateTime->diff($endDateTime);

        // Вместо прямой установки форматированного времени, считаем дни, часы и минуты
        $days = $interval->format('%a');
        $hours = $interval->format('%H');
        $minutes = $interval->format('%I');

        // Возвращаем форматированную строку
        return "⏳ Событие закончится через: {$days} дн. {$hours} чс. {$minutes} мин.";
    }

    protected function notifyCharacterAboutVolcanicEruption($character, $damage, $endTime)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "⚠️ Внимание! *Извержение вулкана* принесло урон вашему персонажу.\n\n";
        $message .= "🔥 Урон: *{$damage}* HP.\n";
        $message .= "🕒 Событие продлится до *{$endTime}*.\n\n";
        $message .= "🛑 Рекомендуем переехать на время или спрятаться на территории базы для вашей безопасности.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению (нужно заменить на актуальный путь)
        $photo = base_url('uploads/telegram/volcanic_eruption_image.png'); // Укажите актуальный путь к изображению

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($photo),
            'caption' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
