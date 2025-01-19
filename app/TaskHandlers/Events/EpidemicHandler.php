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
use App\Services\Player\PlayerStateService;

class EpidemicHandler
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $telegramUserModel;
    protected $activeEventModel;
    private   $telegram;
    private   $playerStateService;

    public function __construct()
    {
        $this->characterModel   = new CharacterModel();
        $this->biomeModel       = new BiomeModel();
        $this->eventModel       = new EventModel();
        $this->telegramUserModel= new TelegramUserModel();
        $this->activeEventModel = new ActiveEventModel();

        // Сервис для проверки, находится ли игрок на базе, занимается ли Gather или Explore
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

    /**
     * Основной метод обработки эпидемии. Вызывается по расписанию или игровому циклу.
     */
    public function process()
    {
        // Сначала проверяем 10% шанс (как в старом коде): 90% случаев эпидемия "не срабатывает".
        if (mt_rand(1, 100) > 10) {
            return;
        }

        // Проверяем, активно ли событие Epidemic (в таблице active_events)
        if (!$this->isEventActive('Epidemic')) {
            return;
        }

        // Получаем всех персонажей
        $allCharacters = $this->characterModel->findAll();
        $characterCount = count($allCharacters);

        // Как в старом коде: 1% игроков (округляя вверх) потенциально заражаются
        $affectedCount = ceil($characterCount / 100);
        if ($affectedCount < 1 && $characterCount > 0) {
            $affectedCount = 1; // Чтобы как минимум 1 персонаж
        }

        // Случайным образом выбираем нужное количество персонажей
        for ($i = 0; $i < $affectedCount; $i++) {
            $randomCharacter = $allCharacters[array_rand($allCharacters)];
            $this->applyEpidemicEffects($randomCharacter);
        }
    }

    /**
     * Применяет эффекты эпидемии к выбранному персонажу.
     */
    protected function applyEpidemicEffects(array $character)
    {
        // Смотрим биом, если он не найден — выходим
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return;
        }

        // Определяем шанс заразиться в зависимости от того, где/чем занят персонаж
        $infectionChance = $this->getInfectionChance($character['id']);

        // Выполняем проверку (случайный бросок)
        $roll = mt_rand(1, 100);
        if ($roll > $infectionChance) {
            // Персонаж избежал заражения
            return;
        }

        // Если персонаж заражается, уменьшаем его health и tired
        $this->infectCharacter($character, $biome);
    }

    /**
     * Определяет вероятность заражения.
     * - Если игрок занят Gather/Explore => 90%
     * - Иначе, если он на базе => 6%
     * - Иначе (вне базы) => 50%
     */
    protected function getInfectionChance(int $characterId): int
    {
        // Проверяем, не занят ли он сбором или изучением
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId)
        ) {
            return 90;
        }

        // Иначе смотрим, на базе ли он
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return 6;
        }

        // Если не на базе => 50%
        return 50;
    }

    /**
     * Собственно «заражает» игрока, уменьшая здоровье и выносливость
     * (не опускаем ниже 1), логируем и уведомляем.
     */
    protected function infectCharacter(array $character, array $biome)
    {
        $characterId = $character['id'];

        // Подгружаем актуальные данные (на случай изменений)
        $freshCharacter = $this->characterModel->find($characterId);
        if (!$freshCharacter) {
            return;
        }

        // Если здоровье (health) уже <= 1, выводим критическое сообщение и ничего не меняем
        if ($freshCharacter['health'] <= 1) {
            $this->sendCriticalMessage($freshCharacter);
            return;
        }

        // Аналогично для выносливости
        if ($freshCharacter['tired'] <= 1) {
            $this->sendCriticalMessage($freshCharacter);
            return;
        }

        // Вычислим, насколько уменьшаем (пример: 1..5 для здоровья, 1..3 для выносливости).
        // Либо можно взять из effect_value, если нужно.
        $healthDecrement = mt_rand(1, 5);
        $tiredDecrement  = mt_rand(1, 3);

        // Новые значения, но не опускаем ниже 1
        $newHealth = max(1, $freshCharacter['health'] - $healthDecrement);
        $newTired  = max(1, $freshCharacter['tired']  - $tiredDecrement);

        // Сохраняем
        $this->characterModel->update($characterId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Логируем эффекты в event_effects_log
        $this->logEpidemicEffects($characterId, $healthDecrement, $tiredDecrement);

        // Отправляем уведомление игроку
        $this->notifyCharacter($freshCharacter, $healthDecrement, $tiredDecrement, $newHealth, $newTired);
    }

    /**
     * Запись в event_effects_log — какие поля уменьшили.
     */
    protected function logEpidemicEffects(int $characterId, int $healthDec, int $tiredDec)
    {
        $logModel = new EventEffectsLogModel();
        $eventInfo = $this->eventModel->where('name_english', 'Epidemic')->first();
        if (!$eventInfo) {
            return;
        }

        $effectDetails = json_encode([
            'healthDec' => $healthDec,
            'tiredDec'  => $tiredDec,
        ]);

        $characterInfo = $this->characterModel->find($characterId);

        $logData = [
            'character_id'  => $characterId,
            'event_id'      => $eventInfo['event_id'],
            'effect_details'=> $effectDetails,
            'event_time'    => date('Y-m-d H:i:s'),
            'cell_number'   => $characterInfo['cell_number'] ?? null,
            'biome_id'      => $characterInfo['biome_id']   ?? null,
        ];

        $logModel->addLog($logData);
    }

    /**
     * Уведомление Telegram о снижении health/tired.
     */
    protected function notifyCharacter(array $character, int $healthDec, int $tiredDec, int $newHealth, int $newTired)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "⚠️ *Эпидемия!* Твой персонаж заразился!\n\n";
        $message .= "💖 Здоровье: -{$healthDec} (текущее: {$newHealth})\n";
        $message .= "🥱 Выносливость: -{$tiredDec} (текущее: {$newTired})\n\n";
        $message .= "Берегись, ведь болезнь может прогрессировать. Используй лекарства, чтобы восстановиться! 💉";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
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
            log_message('error', "Ошибка при отправке сообщения: " . $e->getMessage());
        }
    }

    /**
     * Если здоровье/выносливость уже 1 — пишем особое сообщение о критическом состоянии.
     */
    protected function sendCriticalMessage(array $character)
    {
        $telegramUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$telegramUser) {
            return;
        }
        $chatId = $telegramUser['telegram_id'];

        $message = "⚠️ *Эпидемия!* У твоего персонажа критическое состояние (здоровье или выносливость уже на минимуме).\n";
        $message .= "Кажется, болезнь может привести к летальному исходу... Береги героя и срочно найди лекарство!";

        try {
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения о критическом состоянии: " . $e->getMessage());
        }
    }

    /**
     * Проверяет, активно ли событие Epidemic в active_events.
     */
    protected function isEventActive(string $eventNameEnglish): bool
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();
        if (!$eventInfo) {
            return false;
        }

        // ActiveEventModel
        $active = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        return (bool) $active;
    }
}
