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

// Не забудьте правильно подключить ваш сервис.
// Допустим, он находится в App\Services\PlayerStateService:
use App\Services\Player\PlayerStateService;

class FeverHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    protected $playerStateService; // наш сервис проверок
    private   $telegram;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->biomeModel         = new BiomeModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Инициализируем сервис, который умеет проверять,
        // где находится игрок, чем занят и т.д.
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
        // Ищем событие "Fever"
        $eventInfo = $this->eventModel->where('name_english', 'Fever')->first();
        if (!$eventInfo) {
            return; // Событие "Жаркая лихорадка" не найдено
        }

        // Проверяем, активно ли событие (в active_events)
        if (!$this->checkEventIsActive($eventInfo['event_id'])) {
            return; // Событие не активно
        }

        // Получаем список biomes из JSON
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds) || !is_array($biomeIds)) {
            return; // нет биомов, где действует событие
        }

        // Выбираем всех персонажей, кто в затронутых биомах
        $charactersInAffectedBiomes = $this->characterModel->whereIn('biome_id', $biomeIds)->findAll();
        if (!$charactersInAffectedBiomes) {
            return; // никто не попадает под событие
        }

        // Применяем эффект "лихорадки" к каждому
        foreach ($charactersInAffectedBiomes as $character) {
            $this->applyFeverEffects($character, $eventInfo);
        }
    }

    protected function checkEventIsActive($eventId)
    {
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventId)
            ->where('status', 'active')
            ->first();
        return (bool) $activeEvent;
    }

    protected function applyFeverEffects(array $character, array $eventInfo)
    {
        // Сначала делаем "первый" бросок: срабатывание события для данного персонажа = 50%
        if (rand(1, 100) > 50) {
            // Если > 50, нет эффекта
            return;
        }

        // Теперь определяем дополнительный шанс в зависимости от того, чем игрок занят и где он:
        $secondChance = $this->getSecondChance($character['id']);

        // Второй бросок:
        if (rand(1, 100) > $secondChance) {
            // Если не прошёл, значит эффекта нет
            return;
        }

        // Если дошли сюда — применяем дебафф (health/tired не ниже 1).
        $debuffEffect = $this->calculateDebuffEffect($character, $eventInfo);
        if ($debuffEffect) {
            // Логируем
            $this->logEventEffects($character['id'], $debuffEffect, $eventInfo['event_id']);
            // Уведомляем
            $this->notifyCharacter($character, $debuffEffect);
        }
    }

    /**
     * Определяем второй шанс (6% / 50% / 90%)
     */
    protected function getSecondChance(int $characterId): int
    {
        // Если игрок в процессе сбора или исследования => 90%
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId))
        {
            return 90;
        }

        // Если игрок на базе => 6%
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return 6;
        }

        // Иначе => 50%
        return 50;
    }

    /**
     * Собственно расчёт дебаффа. Примерно, как было,
     * но с гарантией что health/tired >= 1.
     */
    protected function calculateDebuffEffect(array $character, array $eventInfo)
    {
        $debuffEffects = [];

        // Подгружаем из БД (чтобы избежать расхождений)
        $freshCharacter = $this->characterModel->find($character['id']);
        if (!$freshCharacter) {
            return null;
        }

        // Смотрим, какой это тип биома
        $biome = $this->biomeModel->find($freshCharacter['biome_id']);
        if (!$biome) {
            return null;
        }

        // Допустим, логика как раньше:
        switch ($biome['biome_type']) {
            case 'wet':  // влажный биом
                // Уменьшаем health
                $healthDebuff = rand(5, 10); // 5-10
                $oldHealth = $freshCharacter['health'];
                $newHealth = max(1, $oldHealth - $healthDebuff);

                // Сохраняем, если есть изменения
                if ($newHealth < $oldHealth) {
                    $debuffEffects['health'] = $newHealth - $oldHealth; // будет отрицательное число
                    $freshCharacter['health'] = $newHealth;
                }
                break;

            case 'dry':  // сухой биом
                // Уменьшаем tired
                $tiredDebuff = rand(3, 7);
                $oldTired = $freshCharacter['tired'];
                $newTired = max(1, $oldTired - $tiredDebuff);

                if ($newTired < $oldTired) {
                    $debuffEffects['tired'] = $newTired - $oldTired;
                    $freshCharacter['tired'] = $newTired;
                }
                break;

            default:
                // Уменьшаем и health, и tired понемногу
                $healthDebuff = rand(1, 3);
                $tiredDebuff  = rand(1, 3);

                $oldHealth = $freshCharacter['health'];
                $oldTired  = $freshCharacter['tired'];

                $newHealth = max(1, $oldHealth - $healthDebuff);
                $newTired  = max(1, $oldTired  - $tiredDebuff);

                if ($newHealth < $oldHealth) {
                    $debuffEffects['health'] = $newHealth - $oldHealth;
                    $freshCharacter['health'] = $newHealth;
                }
                if ($newTired < $oldTired) {
                    $debuffEffects['tired'] = $newTired - $oldTired;
                    $freshCharacter['tired'] = $newTired;
                }
                break;
        }

        // Если массив пуст, значит ничего не изменилось
        if (empty($debuffEffects)) {
            return null;
        }

        // Сохраняем в БД
        $this->characterModel->update($freshCharacter['id'], [
            'health' => $freshCharacter['health'],
            'tired'  => $freshCharacter['tired'],
        ]);

        return $debuffEffects;
    }

    protected function logEventEffects(int $characterId, array $debuffEffect, int $eventId)
    {
        $logModel = new EventEffectsLogModel();

        $effectDetails = json_encode($debuffEffect, JSON_UNESCAPED_UNICODE);

        $logData = [
            'character_id'  => $characterId,
            'event_id'      => $eventId,
            'effect_details'=> $effectDetails,
            'event_time'    => date('Y-m-d H:i:s'),
        ];

        // Дополнительная инфа (ячейка, биом)
        $characterInfo = $this->characterModel->find($characterId);
        if ($characterInfo) {
            $logData['cell_number'] = $characterInfo['cell_number'] ?? null;
            $logData['biome_id']    = $characterInfo['biome_id']    ?? null;
        }

        $logModel->insert($logData);
    }

    protected function notifyCharacter(array $character, array $debuffEffect)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'] ?? null;
        if (!$chatId) {
            return;
        }

        $message = "⚠️ *Жаркая лихорадка!* Тебя подкосило... \n\n";
        $message .= "Твои показатели понизились:\n";
        foreach ($debuffEffect as $param => $change) {
            $changeVal = (int)$change; // обычно отрицательное
            $translatedParam = $this->translateParameter($param);
            $message .= "- {$translatedParam}: {$changeVal}\n";
        }
        $message .= "\nНе забудь позаботиться о восстановлении!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',         'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $message,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения о Fever: " . $e->getMessage());
        }
    }

    protected function translateParameter(string $parameter): string
    {
        $map = [
            'health' => 'Здоровье',
            'tired'  => 'Выносливость',
            // Если нужны другие параметры...
        ];
        return $map[$parameter] ?? $parameter;
    }
}
