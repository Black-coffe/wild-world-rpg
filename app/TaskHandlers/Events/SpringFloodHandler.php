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

// Подключаем сервис
use App\Services\Player\PlayerStateService;

class SpringFloodHandler extends Controller
{
    protected $activeEventModel;
    protected $eventModel;
    protected $characterModel;
    protected $characterTaskModel;
    protected $taskModel;
    protected $telegramUserModel;
    private   $telegram;

    // Сервис для проверок
    protected $playerStateService;

    public function __construct()
    {
        $this->activeEventModel   = new ActiveEventModel();
        $this->eventModel         = new EventModel();
        $this->characterModel     = new CharacterModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->taskModel          = new TaskModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Создаём сервис
        $this->playerStateService = new PlayerStateService();

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
            return; // 70% случаев — пропускаем
        }

        // 2) Ищем событие "SpringFlood"
        $eventInfo = $this->eventModel->where('name_english', 'SpringFlood')->first();
        if (!$eventInfo) {
            return; // событие не найдено
        }

        // 3) Проверяем, активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$activeEvent) {
            return; // неактивно
        }

        // 4) Смотрим biomes
        $biomeIdsJson = $eventInfo['biome_ids'] ?? null;
        if (!$biomeIdsJson) {
            return;
        }
        $biomeIds = json_decode($biomeIdsJson, true);
        if (!is_array($biomeIds)) {
            return;
        }

        // 5) Берём всех персонажей, находящихся в этих биомах
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        if (!$characters) {
            return;
        }

        // effect_value, например 20
        $effectValue = $eventInfo['effect_value'] ?? 10;

        // 6) Применяем к каждому
        foreach ($characters as $character) {
            $this->applySpringFlood($character, $effectValue, $activeEvent);
        }
    }

    /**
     * Основная логика применения урона с учётом базы / занятости.
     */
    protected function applySpringFlood(array $character, float $effectValue, array $activeEvent)
    {
        $charId = $character['id'];

        // Проверяем состояние: база + занятость
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        // 1) Если на базе и не занят => 0% урона
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyProtected($character);
            return;
        }

        // 2) Иначе считаем процент урона:
        //    Если занят (gather||explore) => 100%,
        //    Если не занят => 70%.
        $damageRatio = ($isGathering || $isExploring) ? 1.0 : 0.7;

        // Считаем урон. Примерно, как в прежнем коде:
        $damage = rand(1, $effectValue); // напр. 1..20
        // Допустим, учитываем уровень:
        $levelCoefficient = max(1, min(999, $character['level']) / 100);
        $finalDamage = round(($damage / $levelCoefficient) * $damageRatio);

        // Не опускаем здоровье ниже 0.01
        $oldHealth = $character['health'];
        if ($oldHealth <= 0.01) {
            return; // уже на минимуме
        }
        $newHealth = max(0.01, $oldHealth - $finalDamage);

        // Обновляем
        $this->characterModel->update($charId, [
            'health' => $newHealth,
        ]);

        // Уведомляем
        $this->notifyDamage($character, $oldHealth, $newHealth, $finalDamage, $damageRatio, $activeEvent);
    }

    /**
     * Уведомление о том, что персонаж на базе и в безопасности.
     */
    protected function notifyProtected(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];

        $message = "🌊 *Весенний паводок* бушует!\n\n"
            . "Но ты находишься на своей базе и не занят опасными делами.\n"
            . "Вода не смогла причинить тебе вреда!";

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
        $lostHealth = round($finalDamage, 2);

        $message  = "⚠️ *Весенний паводок* обрушил на тебя потоки воды!\n\n";
        $message .= "Ты потерял примерно *{$lostHealth}* здоровья ";
        $message .= "(урон применён на *{$damagePercent}* силы).\n";
        $message .= "Текущее здоровье: *" . round($newHealth, 2) . "*\n\n";
        $message .= "_Можешь спастись, отойдя в биом, не затронутый паводком, или вернувшись на базу!_\n";

        // добавим немного информации о конце события
        $endTime = $activeEvent['end_time'] ?? '';
        if ($endTime) {
            $message .= "\nСобытие закончится примерно к: `{$endTime}`\n";
        }

        $imagePath = base_url('uploads/telegram/flooded_areas_by_the_river.jpg');

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($imagePath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyDamage: " . $e->getMessage());
        }
    }
}
