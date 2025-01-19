<?php

namespace App\TaskHandlers\Events;

use DateTime;
use App\Models\{
    CharacterModel,
    BiomeModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel
};
use CodeIgniter\Controller;
use Longman\TelegramBot\{Request, Telegram};
use Longman\TelegramBot\Exception\TelegramException;

// Подключаем сервис
use App\Services\Player\PlayerStateService;

class VolcanicEruptionHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private   $telegram;

    // Наш сервис
    protected $playerStateService;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->biomeModel         = new BiomeModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Инициализация сервиса для проверок
        $this->playerStateService = new PlayerStateService();

        $this->initTelegram();
    }

    private function initTelegram()
    {
        $API_KEY      = getenv('telegram.API_KEY');
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
        // 1) Шанс 30%
        if (mt_rand(1, 100) > 30) {
            return; // 70% случаев пропускаем
        }

        // 2) Проверяем, активно ли событие volcanic_eruption
        if (!$this->isEventActive('volcanic_eruption')) {
            return;
        }

        // 3) Ищем ID «Вулканические территории» (по имени или через biome_id)
        $biomeId = $this->getBiomeIdByName('Вулканические территории');
        if (!$biomeId) {
            return;
        }

        // 4) Находим всех персонажей в этом биоме
        $charactersInBiome = $this->characterModel
            ->where('biome_id', $biomeId)
            ->findAll();
        if (!$charactersInBiome) {
            return;
        }

        // 5) Достаем данные самого события (effect_value, end_time, ...)
        $event = $this->eventModel->where('name_english', 'volcanic_eruption')->first();
        if (!$event) {
            return;
        }
        $isActiveEvent = $this->activeEventModel
            ->where('event_id', $event['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$isActiveEvent) {
            return;
        }

        $effectValue = $event['effect_value'] ?? 30; // например 30

        // 6) Применяем эффекты к каждому
        foreach ($charactersInBiome as $character) {
            $this->applyVolcanicEruptionEffects($character, $event, $isActiveEvent, $effectValue);
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

    /**
     * Применяем урон с учётом базы/занятости.
     */
    protected function applyVolcanicEruptionEffects(
        array $character,
        array $event,
        array $isActiveEvent,
        float $effectValue
    ) {
        // Проверяем, не на минимуме ли здоровье
        if ($character['health'] <= 0.01) {
            return;
        }

        // 1) Определяем, на базе ли игрок + занятый/не занятый
        $charId       = $character['id'];
        $onBase       = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering  = $this->playerStateService->isGathering($charId);
        $isExploring  = $this->playerStateService->isExploring($charId);

        // Логика урона
        // Если (onBase && !gather && !explore) => урона 0
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // Иначе ratio=0.7, если неGather/notExplore, ratio=1.0 иначе
        $ratio = 0.7;
        if ($isGathering || $isExploring) {
            $ratio = 1.0;
        }

        // 2) Вычисляем базовый урон
        $damage = $this->calculateDamage($character, $effectValue);
        // Применяем ratio
        $finalDamage = round($damage * $ratio, 2);

        // Вычитаем из здоровья, не опуская ниже 0.01
        $oldHealth = $character['health'];
        $newHealth = max(0.01, $oldHealth - $finalDamage);
        $this->characterModel->update($charId, ['health' => $newHealth]);

        // Уведомляем
        $endTime = $this->formatEventEndTime($isActiveEvent);
        $this->notifyCharacterAboutVolcanicEruption($character, $finalDamage, $endTime, $ratio, $newHealth);
    }

    /**
     * Логика урона (как раньше).
     */
    protected function calculateDamage(array $character, float $effectValue)
    {
        // Можно взять danger_level, survival_difficulty:
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return $effectValue;
        }
        $dangerLevel  = $biome['danger_level']        ?? 5;
        $survDiff     = $biome['survival_difficulty'] ?? 5;

        // Преобразуем effectValue в multiplier
        // (например, effectValue=30 => 30/100=0.3)
        $effectMultiplier = $effectValue / 100.0;

        // Уровень => levelFactor
        $charLevel = max(1, $character['level']);
        // Логарифмическая формула
        $levelFactor = 1 / (1 + log($charLevel + 1));

        // Интеграция danger
        $biomeFactor = ($dangerLevel + $survDiff) / 20.0;

        // Базовое
        $damage = 10 * $effectMultiplier * $biomeFactor * $levelFactor;

        // Рандом ±50%
        $randomFactor = rand(50, 150) / 100.0;
        $damage *= $randomFactor;

        return round($damage, 2);
    }

    protected function formatEventEndTime(array $isActiveEvent)
    {
        $end = $isActiveEvent['end_time'] ?? null;
        if (!$end) {
            return '';
        }

        $endDateTime     = new DateTime($end);
        $currentDateTime = new DateTime();
        $interval = $currentDateTime->diff($endDateTime);

        $days    = $interval->format('%a');
        $hours   = $interval->format('%H');
        $minutes = $interval->format('%I');

        return "⏳ Событие закончится через: {$days} дн. {$hours} чс. {$minutes} мин.";
    }

    /**
     * Если на базе + не занят => уведомляем, что урона нет
     */
    protected function notifyNoDamage(array $character)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $msg  = "🌋 *Извержение вулкана* поблизости...\n\n";
        $msg .= "Но ты на базе и не занят: лава не может причинить вреда!\n";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при notifyNoDamage: " . $e->getMessage());
        }
    }

    protected function notifyCharacterAboutVolcanicEruption(
        array $character,
        float $damage,
        string $endTime,
        float $ratio,
        float $newHealth
    ) {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'];

        $damagePercent = ($ratio >= 1.0) ? "100%" : "70%";
        $msg  = "⚠️ *Извержение вулкана* обрушилось на твоего персонажа!\n\n";
        $msg .= "🔥 Урон: *{$damage}* HP (применено ~{$damagePercent} от полной силы).\n";
        if ($endTime) {
            $msg .= $endTime . "\n\n";
        } else {
            $msg .= "\n";
        }
        $msg .= "🛑 Совет: можешь спастись, укрывшись в другом биоме или на базе (там лава не достанет).\n";
        $msg .= "Текущее здоровье: *" . round($newHealth, 2) . "*\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        $photo = base_url('uploads/telegram/volcanic_eruption_image.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке volcanic notify: " . $e->getMessage());
        }
    }
}
