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
use CodeIgniter\Controller;

// Подключаем сервис (isCharacterOnBase, isGathering, isExploring)
use App\Services\Player\PlayerStateService;

/**
 * Класс TremorHandler
 *
 * Обрабатывает событие "Tremor" (Подземные толчки):
 * 1) С вероятностью 30% — запускаем логику (70% пропуск).
 * 2) Проверяем, активно ли событие (в active_event).
 * 3) Ищем персонажей, чей biome_id в списке eventModel->biome_ids.
 * 4) Для каждого:
 *    - если (база + не Gather/Explore) => 0 урона (notifyNoDamage),
 *    - иначе считаем урон (rand(1..effectValue)),
 *      учитываем уровень персонажа (делим урон) и занятость (100% или 70%),
 *      уменьшаем здоровье не ниже 0.01, логируем, уведомляем игрока.
 */
class TremorHandler extends Controller
{
    protected $activeEventModel;
    protected $eventModel;
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    protected $eventEffectsLogModel;

    private   $telegram;

    /** @var PlayerStateService */
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->activeEventModel     = new ActiveEventModel();
        $this->eventModel           = new EventModel();
        $this->characterModel       = new CharacterModel();
        $this->characterTaskModel   = new CharacterTaskModel();
        $this->taskModel            = new TaskModel();
        $this->telegramUserModel    = new TelegramUserModel();
        $this->eventEffectsLogModel = new EventEffectsLogModel();

        // Сервис для проверки состояния игрока
        $this->playerStateService   = new PlayerStateService();

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

    /**
     * Основной метод:
     * 1) 30% шанс (70% пропуск).
     * 2) Ищем событие Tremor => проверяем активность.
     * 3) Извлекаем biomes => находим персонажей, у которых biome_id в этом списке.
     * 4) Для каждого персонажа вызываем applyTremorEffects.
     */
    public function process()
    {
        // 1) 30% шанс
        if (mt_rand(1, 100) > 30) {
            return; // 70% пропускаем
        }

        // 2) Ищем событие Tremor
        $eventInfo = $this->eventModel->where('name_english', 'Tremor')->first();
        if (!$eventInfo) {
            return; // нет события
        }

        // Проверяем, активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return; // неактивно
        }

        // 3) Смотрим, какие biomes
        if (empty($eventInfo['biome_ids'])) {
            return;
        }
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // effect_value (напр. 40)
        $effectValue = $eventInfo['effect_value'] ?? 10;

        // Находим персонажей в biomes
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();
        if (!$characters) {
            return;
        }

        // 4) Применяем логику
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

        // Проверки базы + задач
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        // 1) Если (база + !gather + !explore) => noDamage
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // 2) ratio = 100% (gather/explore) или 70% (иначе)
        $ratio = ($isGathering || $isExploring) ? 1.0 : 0.7;

        // 3) Если здоровье уже 0.01, skip
        if ($character['health'] <= 0.01) {
            return;
        }

        // генерируем базовый урон rand(1..effectValue)
        $damage = rand(1, $effectValue);

        // Учитываем уровень (делим на level/100)
        $levelCoefficient = max(1, min(999, $character['level']) / 100);
        $finalDamage = round(($damage / $levelCoefficient) * $ratio);

        // Уменьшаем здоровье
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
     * Уведомляем, если персонаж не пострадал (на базе, без задач).
     */
    protected function notifyNoDamage(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "🌎 *Подземные толчки*...\n\n"
            . "Но ты на базе и не рискуешь — толчки не нанесли тебе урона!";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка notifyNoDamage: ".$e->getMessage());
        }
    }

    /**
     * Уведомляем об уроне
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

        $ratioText = ($ratio >= 1.0) ? "полной силой (100%)" : "частично (70%)";

        $message  = "⚠️ *Подземные толчки!* \n\n";
        $message .= "Ты потерял ~*{$finalDamage}* здоровья ({$ratioText}).\n";
        $message .= "Текущее здоровье: *" . round($newHealth,2) . "*\n\n";
        $message .= "_Укройся на базе или уйди в безопасный биом!_\n";

        if (isset($activeEvent['end_time'])) {
            $message .= "\nСобытие завершится примерно к: `{$activeEvent['end_time']}`.\n";
        }

        $photoPath = base_url('uploads/telegram/earthquake.png');
        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photoPath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка notifyDamage: ".$e->getMessage());
        }
    }

    /**
     * Логируем эффекты (damage,newHealth) в event_effects_log
     */
    protected function logTremorEffects(int $characterId, int $eventId, float $damage, float $newHealth)
    {
        $effectDetails = json_encode([
            'damage'    => $damage,
            'newHealth' => $newHealth,
        ], JSON_UNESCAPED_UNICODE);

        $data = [
            'character_id'   => $characterId,
            'event_id'       => $eventId,
            'effect_details' => $effectDetails,
            'event_time'     => date('Y-m-d H:i:s'),
        ];

        $this->eventEffectsLogModel->insert($data);
    }
}
