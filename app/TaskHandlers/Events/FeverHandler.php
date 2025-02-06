<?php

namespace App\TaskHandlers\Events;

use App\Models\{
    CharacterModel,
    BiomeModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel,
    EventEffectsLogModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Допустим, этот сервис проверки состояния (где игрок, чем занят) у вас лежит в App\Services\Player
use App\Services\Player\PlayerStateService;

/**
 * Класс FeverHandler
 *
 * Обрабатывает событие "Fever" (Жаркая лихорадка):
 *  - Проверяем, активно ли событие (active_events).
 *  - Ищем биомы, на которые оно распространяется (biome_ids).
 *  - Для каждого персонажа в этих биомах с некой вероятностью
 *    снижаем (health/tired), логируем изменения и отправляем уведомление.
 */
class FeverHandler
{
    /** @var CharacterModel */
    protected $characterModel;

    /** @var BiomeModel */
    protected $biomeModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var ActiveEventModel */
    protected $activeEventModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var PlayerStateService Сервис, проверяющий текущее состояние игрока (Gather/Explore/Base) */
    protected $playerStateService;

    /** @var Telegram */
    private $telegram;

    public function __construct()
    {
        // Инициализируем все нужные модели
        $this->characterModel     = new CharacterModel();
        $this->biomeModel         = new BiomeModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Сервис, отвечающий за логику "isGathering"/"isExploring"/"isCharacterOnBase" и т.д.
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $this->initTelegram();
    }

    /**
     * Метод инициализации Telegram (вызывается из конструктора).
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
     * Запуск логики "Fever" (Жаркая лихорадка).
     * 1) Находим событие Fever в таблице events.
     * 2) Проверяем, активно ли оно (active_events).
     * 3) Извлекаем biomes, на которые распространяется.
     * 4) Находим персонажей в этих биомах, для каждого срабатывает двуступенчатая проверка вероятности.
     */
    public function process()
    {
        // 1. Ищем событие "Fever"
        $eventInfo = $this->eventModel
            ->where('name_english', 'Fever')
            ->first();
        if (!$eventInfo) {
            return; // Событие "Жаркая лихорадка" не найдено в таблице events
        }

        // 2. Проверка, активно ли в active_events (status='active')
        if (!$this->checkEventIsActive($eventInfo['event_id'])) {
            return; // Событие не активно
        }

        // 3. Biomes (берём из поля JSON 'biome_ids')
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds) || !is_array($biomeIds)) {
            return; // нет биомов, где действует событие
        }

        // 4. Выбираем всех персонажей, у которых biome_id входит в указанный список
        $charactersInAffectedBiomes = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();
        if (!$charactersInAffectedBiomes) {
            return; // никто не попадает под событие
        }

        // 5. Применяем эффект "лихорадки" к каждому
        foreach ($charactersInAffectedBiomes as $character) {
            $this->applyFeverEffects($character, $eventInfo);
        }
    }

    /**
     * Проверка в таблице active_events: статус должен быть 'active'
     */
    protected function checkEventIsActive($eventId): bool
    {
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventId)
            ->where('status', 'active')
            ->first();
        return (bool) $activeEvent;
    }

    /**
     * Применяет эффекты лихорадки к персонажу, если пройдут две ступени вероятности.
     *
     * Этапы:
     * 1) Первый бросок: 50% (шанс для каждого персонажа)
     * 2) Второй бросок: зависит от состояния:
     *    - Gather/Explore => 90%
     *    - На базе => 6%
     *    - Иначе => 50%
     * 3) Если оба броска успех — снижаем (health / tired) согласно biome_type.
     *    Сохраняем изменения, логируем, уведомляем.
     */
    protected function applyFeverEffects(array $character, array $eventInfo)
    {
        // Первый бросок: 50%
        if (rand(1, 100) > 50) {
            return; // не повлияло
        }

        // Второй бросок: зависит от действий игрока
        $secondChance = $this->getSecondChance($character['id']);
        if (rand(1, 100) > $secondChance) {
            return; // не повлияло
        }

        // Если дошли до сюда — эффекты Fever
        $debuffEffect = $this->calculateDebuffEffect($character, $eventInfo);
        if ($debuffEffect) {
            // Логируем изменения
            $this->logEventEffects($character['id'], $debuffEffect, $eventInfo['event_id']);
            // Уведомляем игрока
            $this->notifyCharacter($character, $debuffEffect);
        }
    }

    /**
     * Вторая проверка (secondChance):
     * - Gather/Explore => 90%
     * - На базе => 6%
     * - Иначе => 50%
     */
    protected function getSecondChance(int $characterId): int
    {
        // 1) Если собирает или исследует, высокая вероятность "подхватить" лихорадку
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId)
        ) {
            return 90;
        }

        // 2) Если игрок на базе, низкий шанс
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return 6;
        }

        // 3) Иначе 50%
        return 50;
    }

    /**
     * Логика снижения health/tired. Условно привязываем к biome_type:
     * - wet (влажный) => сильнее бьёт по Health
     * - dry (сухой) => сильнее по Tired
     * - default => по обоим, но меньше
     */
    protected function calculateDebuffEffect(array $character, array $eventInfo)
    {
        // Подгружаем актуальные данные (во избежание рассинхронизации)
        $freshCharacter = $this->characterModel->find($character['id']);
        if (!$freshCharacter) {
            return null;
        }

        // Узнаём biome_type
        $biome = $this->biomeModel->find($freshCharacter['biome_id']);
        if (!$biome) {
            return null;
        }

        $debuffEffects = [];

        switch ($biome['biome_type']) {
            case 'wet':
                // Уменьшаем здоровье (health)
                $healthDebuff = rand(5, 10); // например, 5..10
                $oldHealth    = $freshCharacter['health'];
                $newHealth    = max(1, $oldHealth - $healthDebuff);

                if ($newHealth < $oldHealth) {
                    $debuffEffects['health'] = $newHealth - $oldHealth; // будет отрицательно
                    $freshCharacter['health'] = $newHealth;
                }
                break;

            case 'dry':
                // Уменьшаем выносливость (tired)
                $tiredDebuff = rand(3, 7);
                $oldTired    = $freshCharacter['tired'];
                $newTired    = max(1, $oldTired - $tiredDebuff);

                if ($newTired < $oldTired) {
                    $debuffEffects['tired'] = $newTired - $oldTired;
                    $freshCharacter['tired'] = $newTired;
                }
                break;

            default:
                // Универсальный случай: и health, и tired немного снижаются
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

        // Если массив пуст => ничего не изменили
        if (empty($debuffEffects)) {
            return null;
        }

        // Сохраняем новые значения
        $this->characterModel->update($freshCharacter['id'], [
            'health' => $freshCharacter['health'],
            'tired'  => $freshCharacter['tired'],
        ]);

        return $debuffEffects;
    }

    /**
     * Логируем влияние события в таблицу event_effects_log.
     */
    protected function logEventEffects(int $characterId, array $debuffEffect, int $eventId)
    {
        $logModel = new EventEffectsLogModel();

        // Подготавливаем JSON с деталями изменений
        $effectDetails = json_encode($debuffEffect, JSON_UNESCAPED_UNICODE);

        // Формируем базовую запись
        $logData = [
            'character_id'   => $characterId,
            'event_id'       => $eventId,
            'effect_details' => $effectDetails,
            'event_time'     => date('Y-m-d H:i:s'),
        ];

        // Дополнительная info: cell_number, biome_id
        $characterInfo = $this->characterModel->find($characterId);
        if ($characterInfo) {
            $logData['cell_number'] = $characterInfo['cell_number'] ?? null;
            $logData['biome_id']    = $characterInfo['biome_id']    ?? null;
        }

        // Сохраняем
        $logModel->insert($logData);
    }

    /**
     * Отправляем сообщение пользователю, что параметры понижены.
     */
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

        // Формируем текст
        $message = "⚠️ *Жаркая лихорадка!* Тебя подкосило...\n\n";
        $message .= "Твои показатели понизились:\n";

        // Каждое изменённое поле (health/tired...)
        foreach ($debuffEffect as $param => $change) {
            // $change обычно отрицательное число
            $translatedParam = $this->translateParameter($param);
            $message .= "- {$translatedParam}: {$change}\n";
        }

        $message .= "\nНе забудь восстановиться — лихорадка не шутки!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
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

    /**
     * Переводим поле health => 'Здоровье', tired => 'Выносливость' и т.д.
     */
    protected function translateParameter(string $parameter): string
    {
        $map = [
            'health' => 'Здоровье',
            'tired'  => 'Выносливость',
            // Можно добавить strength/intellect при необходимости
        ];
        return $map[$parameter] ?? $parameter;
    }
}
