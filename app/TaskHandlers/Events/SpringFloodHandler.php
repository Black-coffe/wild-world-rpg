<?php

namespace App\TaskHandlers\Events;

use App\Models\{
    ActiveEventModel,
    EventModel,
    CharacterModel,
    CharacterTaskModel,
    TaskModel,
    TelegramUserModel
};
use CodeIgniter\Controller;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

// Подключаем сервис (isCharacterOnBase, isGathering, isExploring)
use App\Services\Player\PlayerStateService;

/**
 * Класс SpringFloodHandler
 *
 * Обрабатывает событие "SpringFlood" (Весенний паводок):
 * 1) С вероятностью 30% запускаем логику (70% пропуск).
 * 2) Проверяем, активно ли событие в ActiveEventModel (status='active').
 * 3) Извлекаем biomes (eventInfo['biome_ids']).
 * 4) Ищем персонажей, чьи biome_id в этом списке.
 * 5) Для каждого персонажа:
 *    - если на базе и не занят Gather/Explore => 0% урона,
 *    - иначе считаем урон (случайно 1..effectValue) с учётом уровня и занятости (100% или 70%).
 * 6) Уведомляем игрока, показывая потерю здоровья либо защиту базы.
 */
class SpringFloodHandler extends Controller
{
    /** @var ActiveEventModel */
    protected $activeEventModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var CharacterModel */
    protected $characterModel;

    /** @var CharacterTaskModel */
    protected $characterTaskModel;

    /** @var TaskModel */
    protected $taskModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var Telegram */
    private $telegram;

    /** @var PlayerStateService */
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->activeEventModel   = new ActiveEventModel();
        $this->eventModel         = new EventModel();
        $this->characterModel     = new CharacterModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Сервис для проверок
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

    /**
     * Основной метод:
     * 1) 30% вероятность (70% пропуск).
     * 2) Проверяем "SpringFlood" → status='active'.
     * 3) biomes → персонажи, кто в этих biomes.
     * 4) Для каждого → applySpringFlood.
     */
    public function process()
    {
        // 1) 30% шанс
        if (mt_rand(1, 100) > 30) {
            return; // ~70% случаев пропуск
        }

        // 2) Ищем событие "SpringFlood"
        $eventInfo = $this->eventModel->where('name_english', 'SpringFlood')->first();
        if (!$eventInfo) {
            return; // событие не найдено
        }

        // Проверяем, активно ли (status='active')
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return; // неактивно
        }

        // 3) Получаем список biomes
        $biomeIdsJson = $eventInfo['biome_ids'] ?? null;
        if (!$biomeIdsJson) {
            return;
        }
        $biomeIds = json_decode($biomeIdsJson, true);
        if (!is_array($biomeIds)) {
            return;
        }

        // Находим персонажей, у которых biome_id в списке
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        if (!$characters) {
            return;
        }

        // effect_value (например 20)
        $effectValue = $eventInfo['effect_value'] ?? 10;

        // 4) Применяем к каждому
        foreach ($characters as $character) {
            $this->applySpringFlood($character, $effectValue, $activeEvent);
        }
    }

    /**
     * Логика урона:
     * - если на базе + без Gather/Explore => нет урона
     * - иначе:
     *   → damage = rand(1, effectValue),
     *   → умножаем (или делим) на уровень, занятость (Gather/Explore => 100%, иначе 70%)
     *   → newHealth = max(0.01, oldHealth - damage)
     *   → уведомляем
     */
    protected function applySpringFlood(array $character, float $effectValue, array $activeEvent)
    {
        $charId = $character['id'];

        // Проверяем базу/задачи
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        // (1) Если на базе + не занят => 0% урона
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyProtected($character);
            return;
        }

        // (2) Иначе
        // damageRatio=1.0 при Gather/Explore, 0.7 иначе
        $damageRatio = ($isGathering || $isExploring) ? 1.0 : 0.7;

        // Базовый damage (1..effectValue)
        $damage = rand(1, $effectValue);

        // Учитываем уровень (например, делим на level/100)
        $charLevel = max(1, min(999, $character['level']));
        $levelCoefficient = $charLevel / 100.0;
        // Или обратное: (damage / (charLevel/100)) => damage * (100/charLevel)
        // В примере: $finalDamage = round(($damage / $levelCoefficient) * $damageRatio);

        $finalDamage = round($damage * (100 / $charLevel) * $damageRatio);

        // 0.01 - минимум
        $oldHealth = $character['health'];
        if ($oldHealth <= 0.01) {
            return;
        }
        $newHealth = max(0.01, $oldHealth - $finalDamage);

        // Сохраняем
        $this->characterModel->update($charId, [
            'health' => $newHealth,
        ]);

        // Уведомляем
        $this->notifyDamage($character, $oldHealth, $newHealth, $finalDamage, $damageRatio, $activeEvent);
    }

    /**
     * Уведомляем, что персонаж на базе
     */
    protected function notifyProtected(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];

        $message = "🌊 *Весенний паводок* бушует,\nно ты на базе и не выполняешь сложных задач.\n"
            . "Вода не смогла нанести тебе вред!";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при notifyProtected: " . $e->getMessage());
        }
    }

    /**
     * Уведомление об уроне
     */
    protected function notifyDamage(
        array $character,
        float $oldHealth,
        float $newHealth,
        float $finalDamage,
        float $damageRatio,
        array $activeEvent
    ) {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];
        $damagePercent = ($damageRatio >= 1.0) ? "100%" : "70%";
        $lostHealth = round($finalDamage, 2);

        $message  = "⚠️ *Весенний паводок*!\n\n"
            . "Потоки воды нанесли тебе *{$lostHealth}* урона (силой ~{$damagePercent}).\n"
            . "Текущее здоровье: *" . round($newHealth, 2) . "*\n\n"
            . "_Рекомендация: покинуть опасный биом или укрыться на базе._";

        $endTime = $activeEvent['end_time'] ?? '';
        if ($endTime) {
            $message .= "\nСобытие закончится к: `{$endTime}`\n";
        }

        $imagePath = base_url('uploads/telegram/flooded_areas_by_the_river.jpg');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                            ['text' => '🎉 События',       'callback_data' => 'events']
                        ]
                    ]
                ]),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при notifyDamage: " . $e->getMessage());
        }
    }
}
