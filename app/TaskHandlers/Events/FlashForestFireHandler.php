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

// Подключаем наш сервис проверок местоположения и статуса игрока
use App\Services\Player\PlayerStateService;

class FlashForestFireHandler extends Controller
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
        $this->characterModel    = new CharacterModel();
        $this->biomeModel        = new BiomeModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализируем сервис
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
        // 1) Сначала случайный шанс 20% (как было в исходном коде)
        if (mt_rand(1, 100) > 20) {
            // 80% случаев - пожар не обрабатываем в этом цикле
            return;
        }

        // 2) Проверяем, активно ли событие "FlashForestFire"
        $activeEvent = $this->isEventActive('FlashForestFire');
        if (!$activeEvent) {
            return;  // Событие неактивно
        }

        // 3) Извлекаем biomes, где действует пожар
        if (!isset($activeEvent['biome_ids'])) {
            return;
        }
        $affectedBiomes = json_decode($activeEvent['biome_ids'], true);
        if (!is_array($affectedBiomes)) {
            return; // Ошибка декодирования biomes
        }

        // 4) Для каждого биома - ищем персонажей
        foreach ($affectedBiomes as $biomeId) {
            $charactersInBiome = $this->characterModel
                ->where('biome_id', $biomeId)
                ->findAll();

            // Применяем огонь к каждому
            foreach ($charactersInBiome as $character) {
                $this->applyFireEffects($character, $activeEvent);
            }
        }
    }

    /**
     * Проверяем, есть ли запись в active_events для этого eventNameEnglish
     * со статусом "active". Возвращаем массив или false.
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
            // Добавим biomes (чтобы downstream мог их взять)
            $activeEvent['biome_ids'] = $eventInfo['biome_ids'];
            $activeEvent['effect_value'] = $eventInfo['effect_value'];
            return $activeEvent;
        }

        return false;
    }

    /**
     * Применяем пожар к конкретному персонажу (учитывая логику базы, gather, explore).
     */
    protected function applyFireEffects(array $character, array $activeEvent)
    {
        $characterId = $character['id'];

        // 1) Если игрок на базе -> пожар не касается
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return;
        }

        // 2) Определяем шанс срабатывания урона:
        //    - если gather или explore => 75%
        //    - иначе => 50%
        $chance = 50;
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId))
        {
            $chance = 75;
        }

        // 3) Рандом: если бросок > chance, урона нет.
        if (mt_rand(1, 100) > $chance) {
            return;
        }

        // 4) Если дошли сюда - применяем урон.
        //    Проверим, что есть поле 'effect_value' (например 78).
        if (!isset($activeEvent['effect_value'])) {
            return;
        }

        $damageValue = $activeEvent['effect_value'];

        // Допустим, используем какую-то формулу
        // (как в коде, calculateFireDamage). Упростим/воспользуемся
        $damage = $this->calculateDamage($character, $damageValue);

        // Уменьшаем health и tired, не ниже 1
        $newHealth = max(1, $character['health'] - $damage);
        // Если хотим снизить и выносливость, например вдвое меньше:
        $tiredDamage = round($damage / 2);
        $newTired  = max(1, $character['tired'] - $tiredDamage);

        // Обновляем персонажа
        $this->characterModel->update($characterId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Оповещение
        $this->notifyCharacterAboutFire($character, $damage, $tiredDamage, $activeEvent);
    }

    /**
     * Пример расчёта урона. Можно взять логику из original code (calculateFireDamage).
     */
    protected function calculateDamage(array $character, float $baseEffectValue): float
    {
        // Допустим, учитываем уровень, danger_level, randomFactor:
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            // если нет данных о биоме, вернём базовый
            return $baseEffectValue;
        }

        $danger     = $biome['danger_level']         ?? 5;
        $difficulty = $biome['survival_difficulty']  ?? 5;
        $charLevel  = $character['level']            ?? 1;

        $levelFactor = max(1, 100 - $charLevel) / 100.0;
        $biomeFactor = ($danger + $difficulty) / 20.0;

        // Бросок от 0.5 до 1.5 (плюс-минус 50%)
        $randomFactor = rand(50, 150) / 100.0;

        $damage = $baseEffectValue * $levelFactor * $biomeFactor * $randomFactor;
        // округлим
        return round($damage, 2);
    }

    /**
     * Отправляем сообщение о полученном уроне (health/tired).
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

        // Сформируем текст
        $message = "🔥 *Внезапный лесной пожар!* Тебя задело пламенем...\n\n";
        $message .= "Получен урон по здоровью: *{$damage}*\n";
        $message .= "Понижена выносливость на: *{$tiredDamage}*\n";

        // (можно добавить время окончания события, если хотите)
        // ...

        $message .= "\nСовет: скорее отойди на безопасное расстояние или вернись на базу!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        // Допустим, есть картинка для пожара
        $photoPath = base_url('uploads/telegram/huge_forest_fires.png');

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
