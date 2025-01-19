<?php

namespace App\TaskHandlers\Events;

use App\Models\{
    ActiveEventModel,
    EventModel,
    CharacterModel,
    CharacterTaskModel,
    TaskModel,
    TelegramUserModel,
    EventEffectsLogModel
};
use Longman\TelegramBot\{Request, Telegram};
use Longman\TelegramBot\Exception\TelegramException;
use App\Services\Player\PlayerStateService;

class TremorHandler
{
    protected $activeEventModel;
    protected $eventModel;
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    protected $eventEffectsLogModel;

    private   $telegram;
    protected $playerStateService;

    public function __construct()
    {
        $this->activeEventModel     = new ActiveEventModel();
        $this->eventModel           = new EventModel();
        $this->characterModel       = new CharacterModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->taskModel            = new TaskModel();
        $this->telegramUserModel    = new TelegramUserModel();
        $this->eventEffectsLogModel = new EventEffectsLogModel();

        // Сервис для проверок состояния игрока
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
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
        // 1) 30% шанс
        if (mt_rand(1, 100) > 30) {
            return; // 70% случаев выходим
        }

        // 2) Ищем событие "Tremor"
        $eventInfo = $this->eventModel->where('name_english', 'Tremor')->first();
        if (!$eventInfo) {
            return; // нет события
        }

        // 3) Проверяем активность
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return; // неактивно
        }

        // 4) Biomes
        if (empty($eventInfo['biome_ids'])) {
            return;
        }
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // effect_value (например 40)
        $effectValue = $eventInfo['effect_value'] ?? 10;

        // 5) Персонажи в biomes
        $characters = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();
        if (!$characters) {
            return;
        }

        // 6) Применяем логику к каждому
        foreach ($characters as $character) {
            $this->applyTremorEffects($character, $effectValue, $activeEvent);
        }
    }

    /**
     * Применяем подземные толчки к персонажу.
     */
    protected function applyTremorEffects(array $character, float $effectValue, array $activeEvent)
    {
        $charId      = $character['id'];
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        // 1) Если база + не занятость => 0% урона
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // 2) Определяем множитель урона: 70% (не на базе и не занят), либо 100% (gather/explore)
        $ratio = ($isGathering || $isExploring) ? 1.0 : 0.7;

        // 3) Если здоровье уже на минимуме, не наносим
        if ($character['health'] <= 0.01) {
            return;
        }

        // Генерируем базовый урон
        $damage = rand(1, $effectValue);

        // Учитываем уровень (как в старом коде: делим)
        $levelCoefficient = max(1, min(999, $character['level']) / 100);
        $finalDamage = round(($damage / $levelCoefficient) * $ratio);

        // Уменьшаем здоровье, не опускаем ниже 0.01
        $oldHealth = $character['health'];
        $newHealth = max(0.01, $oldHealth - $finalDamage);

        // Обновляем
        $this->characterModel->update($charId, ['health' => $newHealth]);

        // Логируем
        $this->logTremorEffects($charId, $activeEvent['event_id'], $finalDamage, $newHealth);

        // Уведомляем
        $this->notifyDamage($character, $oldHealth, $newHealth, $finalDamage, $ratio, $activeEvent);
    }

    /**
     * Если персонаж защищён (на базе + не занят).
     */
    protected function notifyNoDamage(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "🌎 *Подземные толчки* ощущаются...\n\n"
            . "Но ты на своей базе и не занят опасной работой,\n"
            . "Поэтому толчки не причинили вреда!";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyNoDamage: " . $e->getMessage());
        }
    }

    /**
     * Уведомление о полученном уроне.
     */
    protected function notifyDamage(
        array $character,
        float $oldHealth,
        float $newHealth,
        float $finalDamage,
        float $ratio,
        array $activeEvent
    ) {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];

        $damagePercent = ($ratio >= 1.0) ? "100%" : "70%";
        $message  = "⚠️ *Подземные толчки!* \n\n";
        $message .= "Ты потерял *{$finalDamage}* здоровья (урон на {$damagePercent} силы).\n";
        $message .= "Текущее здоровье: *" . round($newHealth, 2) . "*\n\n";
        $message .= "_Можешь спастись, укрывшись на базе или перейдя в безопасный биом!_\n";

        if (isset($activeEvent['end_time'])) {
            $message .= "\nСобытие закончится к: `{$activeEvent['end_time']}`\n";
        }

        $imagePath = base_url('uploads/telegram/earthquake.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyDamage: " . $e->getMessage());
        }
    }

    /**
     * Запись в event_effects_log.
     */
    protected function logTremorEffects(int $characterId, int $eventId, float $damage, float $newHealth)
    {
        $effectDetails = json_encode([
            'damage'    => $damage,
            'newHealth' => $newHealth,
        ]);

        $logData = [
            'character_id'  => $characterId,
            'event_id'      => $eventId,
            'effect_details'=> $effectDetails,
            'event_time'    => date('Y-m-d H:i:s'),
        ];

        $this->eventEffectsLogModel->insert($logData);
    }
}
