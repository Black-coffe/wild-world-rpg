<?php

namespace App\TaskHandlers\Events;

use DateTime;
use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    BiomeModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel
};
use Longman\TelegramBot\{Request, Telegram};
use Longman\TelegramBot\Exception\TelegramException;

// Подключаем сервис (isCharacterOnBase, isGathering, isExploring)
use App\Services\Player\PlayerStateService;

/**
 * Класс VolcanicEruptionHandler
 *
 * Обрабатывает событие "volcanic_eruption" (извержение вулкана):
 * 1) С вероятностью 30% (70% случаев пропуска) вызывается метод process().
 * 2) Проверяем, активно ли событие (activeEvent).
 * 3) Находим ID "Вулканические территории" (через getBiomeIdByName()) или через biome_ids события.
 * 4) Определяем, какие персонажи находятся в этом биоме.
 * 5) Для каждого персонажа считаем урон (учитывая базу/задачи):
 *    - Если (база + без Gather/Explore) => 0% урона
 *    - Иначе ratio=70% (если не Gather/Explore) или 100% (Gather/Explore).
 *    - Формула урона: учитывает effectValue, danger_level, survival_difficulty, уровень персонажа, random ±50%.
 * 6) Уменьшаем здоровье, отправляем уведомление (с картинкой), информируем о конце события.
 */
class VolcanicEruptionHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private   $telegram;

    /** @var PlayerStateService */
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel    = new CharacterModel();
        $this->biomeModel        = new BiomeModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализация сервиса
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $this->initTelegram();
    }

    /**
     * Настраиваем Telegram (SDK)
     */
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

    /**
     * Основной метод:
     * 1) 30% шанс
     * 2) Проверяем "volcanic_eruption" активно ли
     * 3) Пытаемся найти biome_id "Вулканические территории"
     * 4) Ищем персонажей -> applyVolcanicEruptionEffects()
     */
    public function process()
    {
        // 1) Шанс 30%
        if (mt_rand(1, 100) > 30) {
            return; // 70% пропуск
        }

        // 2) Проверка активности
        if (!$this->isEventActive('volcanic_eruption')) {
            return;
        }

        // 3) Ищем ID биома "Вулканические территории"
        $biomeId = $this->getBiomeIdByName('Вулканические территории');
        if (!$biomeId) {
            // Можем также получить biome_ids напрямую из eventModel->biome_ids
            return;
        }

        // 4) Получаем всех персонажей в этом биоме
        $charactersInBiome = $this->characterModel
            ->where('biome_id', $biomeId)
            ->findAll();
        if (!$charactersInBiome) {
            return;
        }

        // Достаем данные события
        $event = $this->eventModel
            ->where('name_english', 'volcanic_eruption')
            ->first();
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

        // effect_value (пример: 30)
        $effectValue = $event['effect_value'] ?? 30;

        // Применяем эффекты к персонажам
        foreach ($charactersInBiome as $character) {
            $this->applyVolcanicEruptionEffects($character, $event, $isActiveEvent, $effectValue);
        }
    }

    /**
     * Проверяем, активно ли событие по name_english
     */
    protected function isEventActive(string $eventNameEnglish): bool
    {
        $event = $this->eventModel
            ->where('name_english', $eventNameEnglish)
            ->first();
        if (!$event) {
            return false;
        }

        $isActive = $this->activeEventModel
            ->where('event_id', $event['event_id'])
            ->where('status', 'active')
            ->first();

        return !empty($isActive);
    }

    /**
     * Получаем biome_id по русскому названию (например, "Вулканические территории").
     */
    protected function getBiomeIdByName(string $biomeName): ?int
    {
        $biome = $this->biomeModel->where('name', $biomeName)->first();
        return $biome ? (int)$biome['id'] : null;
    }

    /**
     * Применяем урон от извержения к персонажу
     */
    protected function applyVolcanicEruptionEffects(
        array $character,
        array $event,
        array $isActiveEvent,
        float $effectValue
    ) {
        // Если здоровье уже 0.01 => пропускаем
        if ($character['health'] <= 0.01) {
            return;
        }

        // Проверки (база+задачи)
        $charId       = $character['id'];
        $onBase       = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering  = $this->playerStateService->isGathering($charId);
        $isExploring  = $this->playerStateService->isExploring($charId);

        // Если (onBase && !gather && !explore) => no damage
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // ratio=0.7 если неGather/notExplore, иначе=1.0
        $ratio = 0.7;
        if ($isGathering || $isExploring) {
            $ratio = 1.0;
        }

        // 2) Считаем урон
        $damage = $this->calculateDamage($character, $effectValue);
        $finalDamage = round($damage * $ratio, 2);

        $oldHealth = $character['health'];
        $newHealth = max(0.01, $oldHealth - $finalDamage);

        $this->characterModel->update($charId, ['health' => $newHealth]);

        // Уведомляем
        $endTime = $this->formatEventEndTime($isActiveEvent);
        $this->notifyCharacterAboutVolcanicEruption($character, $finalDamage, $endTime, $ratio, $newHealth);
    }

    /**
     * Пример расчёта урона:
     * effectValue => multiplier,
     * danger+difficulty => biomeFactor,
     * level => levelFactor,
     * random ±50%.
     */
    protected function calculateDamage(array $character, float $effectValue): float
    {
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return $effectValue;
        }
        $danger     = $biome['danger_level']         ?? 5;
        $difficulty= $biome['survival_difficulty']   ?? 5;
        $charLevel  = max(1, $character['level']);

        // effectValue ~30 => effectMultiplier=0.30
        $effectMultiplier = $effectValue / 100.0;

        // Уровень => levelFactor=1/(1+log(charLevel+1))
        $levelFactor = 1 / (1 + log($charLevel + 1));

        // biomeFactor=(danger+difficulty)/20
        $biomeFactor = ($danger + $difficulty)/20.0;

        // damage=10 * effectMultiplier*biomeFactor*levelFactor
        $baseDamage = 10 * $effectMultiplier * $biomeFactor * $levelFactor;

        // random(50..150)/100 => ±50%
        $randomFactor = rand(50,150)/100.0;
        $damage = $baseDamage * $randomFactor;

        return round($damage, 2);
    }

    protected function formatEventEndTime(array $isActiveEvent): string
    {
        $end = $isActiveEvent['end_time'] ?? null;
        if (!$end) {
            return '';
        }

        $endDateTime     = new DateTime($end);
        $currentDateTime = new DateTime();
        if ($endDateTime < $currentDateTime) {
            return '';
        }

        $interval = $currentDateTime->diff($endDateTime);
        $days    = $interval->format('%a');
        $hours   = $interval->format('%H');
        $minutes = $interval->format('%I');

        return "⏳ Извержение вулкана закончится через: {$days} д. {$hours} ч. {$minutes} мин.";
    }

    /**
     * Уведомление при 0 урона (на базе, не занят)
     */
    protected function notifyNoDamage(array $character)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $msg = "🌋 *Извержение вулкана*...\n\n"
            . "Но ты на базе и без опасных занятий — лава не достаёт тебя!";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $msg,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка notifyNoDamage: " . $e->getMessage());
        }
    }

    /**
     * Уведомление о полученном уроне
     */
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

        $damagePercent = ($ratio>=1.0) ? "100%" : "70%";
        $msg  = "⚠️ *Извержение вулкана!* \n\n";
        $msg .= "🔥 Урон: *{$damage}* HP (примерно {$damagePercent} силы).\n";
        if ($endTime) {
            $msg .= $endTime."\n\n";
        } else {
            $msg .= "\n";
        }
        $msg .= "🛑 Лучше укрыться в другом биоме или на базе!\n";
        $msg .= "Текущее здоровье: *".round($newHealth,2)."*\n";

        // Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data'=>'characterActions'],
                    ['text' => '🎉 События',     'callback_data'=>'events']
                ]
            ]
        ];

        // Фото
        $photo = base_url('uploads/telegram/volcanic_eruption_image.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка volcanic notify: " . $e->getMessage());
        }
    }
}
