<?php

namespace App\TaskHandlers\Events;

use DateTime;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\TelegramUserModel;
use App\Services\Player\PlayerStateService;

/**
 * Класс FlashForestFireHandler
 *
 * Обрабатывает событие "FlashForestFire" (Внезапный лесной пожар):
 *  - Периодически (cron/game-loop) вызывается метод process().
 *  - С вероятностью 20% за «тик» пытаемся применить пожар к персонажам, находящимся
 *    в биомах, указанных в событии.
 *  - Если персонаж находится на базе — урона нет. Если персонаж Gather/Explore — повышенный шанс (75%), иначе 50%.
 *  - Урон высчитывается по формуле (с учётом danger_level, difficulty, уровня персонажа, случайного фактора ±50%).
 *  - Уменьшается health и tired, игрока уведомляем через Telegram.
 */
class FlashForestFireHandler extends Controller
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

    /** @var Telegram */
    private $telegram;

    /** @var PlayerStateService Сервис проверки статуса (isGathering, isExploring, isCharacterOnBase) */
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel    = new CharacterModel();
        $this->biomeModel        = new BiomeModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Сервис, который умеет проверять, где персонаж, чем занят и т.п.
        $this->playerStateService = new PlayerStateService();

        $this->initTelegram();
    }

    /**
     * Инициализация Telegram Bot SDK
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
     * Основной метод "process", вызывается по расписанию или в игровом цикле.
     *
     * 1) С 20%-й вероятностью (80% случаев выходим).
     * 2) Проверяем, активно ли событие "FlashForestFire".
     * 3) Для каждого указанного в событии биома берём список персонажей.
     * 4) Применяем логику урона к каждому персонажу, кто не на базе.
     */
    public function process()
    {
        // 1) Случайный шанс 20%
        if (mt_rand(1, 100) > 20) {
            // 80% случаев - пожара в этом цикле не будет
            return;
        }

        // 2) Проверяем, активно ли событие "FlashForestFire"
        $activeEvent = $this->isEventActive('FlashForestFire');
        if (!$activeEvent) {
            return; // Неактивное событие
        }

        // 3) Извлекаем biomes (из поля biome_ids в event'е)
        if (!isset($activeEvent['biome_ids'])) {
            return;
        }
        $affectedBiomes = json_decode($activeEvent['biome_ids'], true);
        if (!is_array($affectedBiomes)) {
            return; // Ошибка JSON
        }

        // 4) Обходим все биомы и применяем огонь к персонажам
        foreach ($affectedBiomes as $biomeId) {
            $charactersInBiome = $this->characterModel
                ->where('biome_id', $biomeId)
                ->findAll();

            foreach ($charactersInBiome as $character) {
                $this->applyFireEffects($character, $activeEvent);
            }
        }
    }

    /**
     * Проверяем, есть ли в active_events запись со status='active'
     * для события eventNameEnglish.
     *
     * Возвращаем массив (добавляя 'biome_ids', 'effect_value') или false.
     */
    protected function isEventActive(string $eventNameEnglish)
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$eventInfo) {
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Добавим нужные поля из eventInfo, чтобы downstream знал
            $activeEvent['biome_ids']    = $eventInfo['biome_ids'];
            $activeEvent['effect_value'] = $eventInfo['effect_value'];
            return $activeEvent;
        }

        return false;
    }

    /**
     * Применяем логику лесного пожара к персонажу:
     * - Если находится на базе => нет урона
     * - Иначе проверяем 75% шанс (Gather/Explore) или 50% (обычное положение)
     * - При успехе считаем урон (damage) и tiredDamage = damage/2
     * - Уменьшаем health, tired. Уведомляем игрока.
     */
    protected function applyFireEffects(array $character, array $activeEvent)
    {
        $characterId = $character['id'];

        // 1) Если игрок на базе => огонь его не задевает
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return;
        }

        // 2) Определяем вероятность (chance)
        $chance = 50; // базовое
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId)) {
            // Сбор/исследование = повышенная уязвимость
            $chance = 75;
        }

        // 3) Бросок: если > chance => нет урона
        if (mt_rand(1, 100) > $chance) {
            return;
        }

        // 4) Считываем базовый урон (effect_value), если нет - выходим
        if (!isset($activeEvent['effect_value'])) {
            return;
        }
        $damageValue = $activeEvent['effect_value'];

        // Рассчитываем урон (можно опираться на danger_level, уровень персонажа и т.д.)
        $damage = $this->calculateDamage($character, $damageValue);

        // Снижаем здоровье (health) и выносливость (tired)
        $oldHealth = $character['health'];
        $oldTired  = $character['tired'];

        $newHealth = max(0.01, $oldHealth - $damage);
        // Например, tiredDamage вдвое меньше
        $tiredDamage = round($damage / 2);
        $newTired  = max(0.01, $oldTired - $tiredDamage);

        // Обновляем таблицу characters
        $this->characterModel->update($characterId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Уведомляем игрока
        $this->notifyCharacterAboutFire($character, $damage, $tiredDamage, $activeEvent);
    }

    /**
     * Примерная формула урона, учитывая уровень персонажа,
     * danger_level и survival_difficulty биома + случайный фактор ±50%.
     */
    protected function calculateDamage(array $character, float $baseEffectValue): float
    {
        // Получаем данные о биоме (danger_level, survival_difficulty)
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            // Если нет данных о биоме, возвращаем базовое
            return $baseEffectValue;
        }

        $danger     = $biome['danger_level']        ?? 5;
        $difficulty = $biome['survival_difficulty'] ?? 5;
        $charLevel  = $character['level']           ?? 1;

        // levelFactor: чем выше уровень, тем меньше урон.
        // Пример: (100 - charLevel)/100, но минимум 1
        $levelFactor = max(1, 100 - $charLevel) / 100.0;

        // biomeFactor = (danger + difficulty)/20
        // Напр., если danger=7, difficulty=8 => 15/20=0.75
        $biomeFactor = ($danger + $difficulty) / 20.0;

        // randomFactor: от 0.5 до 1.5
        $randomFactor = rand(50, 150) / 100.0;

        // Итог
        $damage = $baseEffectValue
            * $levelFactor
            * $biomeFactor
            * $randomFactor;

        return round($damage, 2);
    }

    /**
     * Уведомляем пользователя в Telegram о том, что он получил урон
     * при лесном пожаре. Прикрепляем картинку (если есть).
     */
    protected function notifyCharacterAboutFire(array $character, float $damage, float $tiredDamage, array $activeEvent)
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
        $message = "🔥 *Внезапный лесной пожар!* Тебя задело пламенем...\n\n";
        $message .= "Получен урон по здоровью: *{$damage}*\n";
        $message .= "Понижена выносливость на: *{$tiredDamage}*\n";
        $message .= "\nСовет: скорее уйди из опасного места или вернись на базу!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к картинке. encodeFile делает fopen() — нужен ЛОКАЛЬНЫЙ путь,
        // не URL. base_url() даёт URL и при пустом app.baseURL (cron) валится.
        $photoPath = FCPATH . 'uploads/telegram/huge_forest_fires.png';

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото (лесной пожар): " . $e->getMessage());
        }
    }
}
