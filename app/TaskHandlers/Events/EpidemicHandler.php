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

/**
 * Класс EpidemicHandler
 *
 * Обрабатывает глобальное или локальное событие "Epidemic":
 * - Раз в некоторый промежуток (cron / игровой цикл) проверяется общий шанс 10%,
 *   что эпидемия "сработает" прямо сейчас.
 * - Из всех персонажей 1% (минимум 1) потенциально заражаются.
 * - Для каждого кандидата запускается индивидуальная проверка:
 *   infectionChance зависит от того, чем персонаж занят (Gather/Explore/На базе).
 * - При успехе броска здоровье (health) и выносливость (tired) персонажа уменьшаются
 *   на случайную величину (1..5 для здоровья, 1..3 для выносливости),
 *   но не опускаются ниже 1.
 * - Вся информация логируется в event_effects_log, а игрок получает уведомление.
 */
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
        // Подключаем необходимые модели и сервисы
        $this->characterModel   = new CharacterModel();
        $this->biomeModel       = new BiomeModel();
        $this->eventModel       = new EventModel();
        $this->telegramUserModel= new TelegramUserModel();
        $this->activeEventModel = new ActiveEventModel();

        // Сервис, позволяющий узнать, находится ли игрок на базе,
        // или занят Gather/Explore
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram API
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
     *
     * Логика:
     * 1) Есть общий шанс 10% (mt_rand(1, 100) <= 10), что эпидемия "активируется" на этом тик/дне.
     * 2) Если событие "Epidemic" в active_events имеет статус 'active',
     *    то переходим к отбору персонажей.
     * 3) Из всех персонажей берем 1% (ceil(...)), минимум 1, выбираем случайным образом.
     * 4) Применяем эффект заражения (applyEpidemicEffects).
     */
    public function process()
    {
        // 1) Общий шанс 10%, иначе 90% случаев мы "пропускаем" эпидемию
        if (mt_rand(1, 100) > 10) {
            return;
        }

        // 2) Проверяем активность события "Epidemic" (в таблице active_events)
        if (!$this->isEventActive('Epidemic')) {
            return;
        }

        // 3) Получаем всех персонажей из таблицы characters
        $allCharacters = $this->characterModel->findAll();
        $characterCount = count($allCharacters);
        if ($characterCount === 0) {
            return;
        }

        // 4) 1% от общего числа (минимум 1) заражаются
        $affectedCount = ceil($characterCount / 100);
        if ($affectedCount < 1) {
            $affectedCount = 1;
        }

        // 5) Случайно выбираем $affectedCount персонажей и применяем эффект
        //    (каждый раз array_rand(...) возвращает один индекс случайного персонажа)
        for ($i = 0; $i < $affectedCount; $i++) {
            $randomCharacter = $allCharacters[array_rand($allCharacters)];
            $this->applyEpidemicEffects($randomCharacter);
        }
    }

    /**
     * Применяет эффекты эпидемии к выбранному персонажу.
     *
     * 1) Проверяем биом (если нет bioma, пропускаем).
     * 2) Смотрим вероятность заражения (infectionChance) в зависимости от PlayerStateService:
     *    - Gather/Explore => 90%
     *    - На базе => 6%
     *    - Иначе => 50%
     * 3) Если бросок mt_rand(1,100) <= infectionChance, персонаж "заражается"
     * 4) Уменьшаем health и tired (не опуская ниже 1), логируем и шлём уведомление.
     */
    protected function applyEpidemicEffects(array $character)
    {
        // Смотрим, какой у персонажа biome_id
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            // Если биом не найден (бывает, если biome_id=0/null), выходим
            return;
        }

        // Вычисляем вероятность заразиться
        $infectionChance = $this->getInfectionChance($character['id']);

        // Бросок
        $roll = mt_rand(1, 100);
        if ($roll > $infectionChance) {
            // Персонаж избежал заражения, ничего не делаем
            return;
        }

        // Иначе персонаж заражается
        $this->infectCharacter($character, $biome);
    }

    /**
     * Определяет вероятность (в %), с которой персонаж заразится при эпидемии.
     * - Если Gather/Explore => 90%
     * - Если на базе => 6%
     * - Иначе (вне базы, не Gather/Explore) => 50%
     */
    protected function getInfectionChance(int $characterId): int
    {
        // Сервис PlayerStateService позволяет проверить различные активности
        if ($this->playerStateService->isGathering($characterId) ||
            $this->playerStateService->isExploring($characterId)
        ) {
            return 90;
        }

        // Если персонаж на базе
        if ($this->playerStateService->isCharacterOnBase($characterId)) {
            return 6;
        }

        // Иначе (гуляет вне базы, не собирает/исследует)
        return 50;
    }

    /**
     * "Заражает" персонажа, снижая показатели health и tired.
     * Нельзя опустить их ниже 1, иначе считаем критическое состояние.
     *
     * @param array $character Текущие данные персонажа
     * @param array $biome     Данные о биоме (необязательно, но может пригодиться)
     */
    protected function infectCharacter(array $character, array $biome)
    {
        $characterId = $character['id'];

        // Подгружаем актуальные данные (вдруг что-то поменялось в health/tired)
        $freshCharacter = $this->characterModel->find($characterId);
        if (!$freshCharacter) {
            return;
        }

        // Если здоровье уже <= 1, отправляем крит.сообщение
        if ($freshCharacter['health'] <= 1) {
            $this->sendCriticalMessage($freshCharacter);
            return;
        }
        // Аналогично выносливость
        if ($freshCharacter['tired'] <= 1) {
            $this->sendCriticalMessage($freshCharacter);
            return;
        }

        // Сколько урона наносим здоровью: от 1 до 5
        $healthDecrement = mt_rand(1, 5);
        // Сколько снимаем выносливости: от 1 до 3
        $tiredDecrement  = mt_rand(1, 3);

        // Новые значения, но не меньше 0.01 (или 1, на ваше усмотрение)
        $newHealth = max(1, $freshCharacter['health'] - $healthDecrement);
        $newTired  = max(1, $freshCharacter['tired']  - $tiredDecrement);

        // Обновляем поля
        $this->characterModel->update($characterId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Пишем лог (какие параметры уменьшили)
        $this->logEpidemicEffects($characterId, $healthDecrement, $tiredDecrement);

        // Уведомляем игрока
        $this->notifyCharacter($freshCharacter, $healthDecrement, $tiredDecrement, $newHealth, $newTired);
    }

    /**
     * Записываем в таблицу event_effects_log, чтобы хранить историю воздействия.
     */
    protected function logEpidemicEffects(int $characterId, int $healthDec, int $tiredDec)
    {
        $logModel = new EventEffectsLogModel();

        // Находим ID события 'Epidemic'
        $eventInfo = $this->eventModel
            ->where('name_english', 'Epidemic')
            ->first();
        if (!$eventInfo) {
            return; // Нет записи о событии => пропускаем лог
        }

        // Формируем JSON-поле с деталями (healthDec, tiredDec)
        $effectDetails = json_encode([
            'healthDec' => $healthDec,
            'tiredDec'  => $tiredDec,
        ]);

        // Узнаём cell_number и biome_id персонажа, чтобы записать в лог
        $characterInfo = $this->characterModel->find($characterId);

        $logData = [
            'character_id'   => $characterId,
            'event_id'       => $eventInfo['event_id'],
            'effect_details' => $effectDetails,
            'event_time'     => date('Y-m-d H:i:s'),
            'cell_number'    => $characterInfo['cell_number'] ?? null,
            'biome_id'       => $characterInfo['biome_id']   ?? null,
        ];

        // Предполагаем, что метод addLog($data) делает insert
        $logModel->addLog($logData);
    }

    /**
     * Уведомляет игрока о снижении health/tired в результате заражения.
     *
     * @param array $character Предыдущие данные персонажа (до обновления), чтобы имя взять
     * @param int $healthDec   Сколько сняли со здоровья
     * @param int $tiredDec    Сколько сняли с выносливости
     * @param float $newHealth Текущее здоровье (после снятия)
     * @param float $newTired  Текущая выносливость (после снятия)
     */
    protected function notifyCharacter(array $character, int $healthDec, int $tiredDec, float $newHealth, float $newTired)
    {
        $telegramUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'] ?? null;
        if (!$chatId) {
            return;
        }

        $message = "⚠️ *Эпидемия!* Твой персонаж заразился!\n\n";
        $message .= "💖 *Здоровье*: -{$healthDec} (текущее: {$newHealth})\n";
        $message .= "🥱 *Выносливость*: -{$tiredDec} (текущее: {$newTired})\n\n";
        $message .= "Болезнь может прогрессировать, будь осторожен. Используй лекарства, чтобы восстановиться! 💉";

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
            log_message('error', "Ошибка при отправке сообщения: " . $e->getMessage());
        }
    }

    /**
     * Если здоровье/выносливость уже на единице, сообщаем,
     * что персонаж в критическом состоянии (а значит, следующая атака может убить).
     */
    protected function sendCriticalMessage(array $character)
    {
        $telegramUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'] ?? null;
        if (!$chatId) {
            return;
        }

        $message = "⚠️ *Эпидемия!* У твоего персонажа критическое состояние (здоровье или выносливость почти на нуле).\n";
        $message .= "Рискуешь потерять героя при следующем заражении. Срочно прими меры и найди лекарство!";

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
     *
     * @param string $eventNameEnglish Например, 'Epidemic'
     */
    protected function isEventActive(string $eventNameEnglish): bool
    {
        $eventInfo = $this->eventModel
            ->where('name_english', $eventNameEnglish)
            ->first();
        if (!$eventInfo) {
            return false;
        }

        $active = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        return (bool) $active;
    }
}
