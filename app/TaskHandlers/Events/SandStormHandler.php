<?php

namespace App\TaskHandlers\Events;

use DateTime;
use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    BiomeModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Подключаем сервис, где лежат методы isCharacterOnBase, isGathering, isExploring
use App\Services\Player\PlayerStateService;

/**
 * Класс SandStormHandler
 *
 * Обрабатывает событие "Sandstorm" (Песчаная буря):
 * 1) С ~25% вероятностью (75% пропуск) запускает логику (process()).
 * 2) Проверяем, активно ли событие (activeEvent).
 * 3) Читаем biomes из eventModel->biome_ids, находим персонажей, находящихся в этих биомах.
 * 4) Для каждого персонажа:
 *    - если на базе и не выполняет Gather/Explore, урона нет;
 *    - иначе рассчитываем урон по "усталости" (tired) и случайно снижаем один из атрибутов (experience/strength/agility/intellect);
 * 5) Уведомляем игрока в Telegram, с учётом того, когда закончится событие.
 */
class SandStormHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private   $telegram;

    // Сервис проверок (на базе ли персонаж, Gathering/Explore)
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel    = new CharacterModel();
        $this->biomeModel        = new BiomeModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализируем сервис проверок
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $this->initTelegram();
    }

    /**
     * Настраиваем Telegram SDK
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
     * Основной метод:
     * 1) С ~25% вероятностью (25% success, 75% skip).
     * 2) Проверяем, активно ли "Sandstorm".
     * 3) Для каждого biomeId из activeEvent -> ищем персонажей, вызываем applySandStormEffects.
     */
    public function process()
    {
        // 1) 25% шанс
        if (mt_rand(1, 100) > 25) {
            return; // 75% случаев пропускаем
        }

        // 2) Проверяем активное событие "Sandstorm"
        $activeEvent = $this->isEventActive('Sandstorm');
        if (!$activeEvent) {
            // Событие неактивно
            return;
        }

        // 3) biomes
        if (!isset($activeEvent['biome_ids'])) {
            return; // нет данных
        }
        $affectedBiomes = json_decode($activeEvent['biome_ids'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return; // ошибка JSON
        }

        // 4) Ищем персонажей в каждом из биомов
        foreach ($affectedBiomes as $biomeId) {
            $charactersInBiome = $this->characterModel
                ->where('biome_id', $biomeId)
                ->findAll();

            // Для каждого персонажа вызываем
            foreach ($charactersInBiome as $character) {
                $this->applySandStormEffects($character, $activeEvent);
            }
        }
    }

    /**
     * Проверяем, есть ли активное событие "Sandstorm",
     * возвращаем массив или false.
     */
    protected function isEventActive(string $eventNameEnglish)
    {
        $eventInfo = $this->eventModel
            ->where('name_english', $eventNameEnglish)
            ->first();

        if (!$eventInfo || empty($eventInfo['biome_ids'])) {
            return false;
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Дополнительно добавим нужные поля (effect_value, biome_ids)
            $activeEvent['biome_ids']    = $eventInfo['biome_ids'];
            $activeEvent['effect_value'] = $eventInfo['effect_value'];
            return $activeEvent;
        }
        return false;
    }

    /**
     * Логика применения бури к персонажу:
     * - если на базе и не Gathering/Exploring => нет урона
     * - иначе снижаем tired (calculateTiredDamage) ± ratio 100%/80%,
     *   и случайно урезаем 1 атрибут (experience/strength/agility/intellect) на 0.01
     * - отправляем уведомление
     */
    protected function applySandStormEffects(array $character, array $activeEvent)
    {
        // 1) Если tired<=0.01, не обрабатываем
        if ($character['tired'] <= 0.01) {
            return;
        }

        $effectValue = $activeEvent['effect_value'] ?? 30; // Пример
        $charId      = $character['id'];

        // Сервисные проверки
        $onBase     = $this->playerStateService->isCharacterOnBase($charId);
        $isGather   = $this->playerStateService->isGathering($charId);
        $isExploring= $this->playerStateService->isExploring($charId);

        // (a) Если (onBase && !isGather && !isExploring) => noDamage
        if ($onBase && !$isGather && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // (b) Иначе
        // ratio = 1.0, если gather||exploring, иначе 0.8
        $ratio = 1.0;
        if (!$isGather && !$isExploring) {
            $ratio = 0.8;
        }

        // Считаем урон по tired
        $tiredDamage = $this->calculateTiredDamage($character, $effectValue) * $ratio;
        $tiredDamage = round($tiredDamage, 2);

        $newTired = max(0.01, $character['tired'] - $tiredDamage);

        // Случайно выберем 1 атрибут (exp/str/agi/int) для уменьшения на 0.01
        $attributes = ['experience','strength','agility','intellect'];
        $attr       = $attributes[array_rand($attributes)];
        $oldVal     = $character[$attr];
        $minus      = 0.0;
        if ($oldVal > 0.01) {
            $minus  = 0.01;
            $oldVal = max(0.01, $oldVal - $minus);
        }

        // Сохраняем
        $this->characterModel->update($charId, [
            'tired' => $newTired,
            $attr   => $oldVal,
        ]);

        // Формируем время окончания события для уведомления
        $endTime = $this->formatEventEndTime($activeEvent);

        // Уведомляем
        $this->notifyDamage($character, $tiredDamage, $attr, $minus, $endTime, $onBase);
    }

    /**
     * Расчет урона по tired,
     * с учётом уровня, danger_level, random ±50%
     */
    protected function calculateTiredDamage(array $character, float $effectValue): float
    {
        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return $effectValue; // fallback
        }

        $charLevel  = (int)$character['level']      ?: 1;
        $danger     = (int)$biome['danger_level']   ?: 5;
        $difficulty = (int)$biome['survival_difficulty'] ?: 5;

        // levelFactor: (100 - level)/100, минимум 1
        $levelFactor = max(1, 100 - $charLevel) / 100.0;
        // biomeFactor: (danger + difficulty)/20
        $biomeFactor = ($danger + $difficulty) / 20.0;
        // randomFactor: [0.5..1.5]
        $randomFactor = rand(50, 150) / 100.0;

        $damage = $effectValue * $levelFactor * $biomeFactor * $randomFactor;
        return round($damage, 2);
    }

    /**
     * Формируем строку "событие закончится через X дн Y час Z мин"
     */
    protected function formatEventEndTime(array $activeEvent): string
    {
        $end = $activeEvent['end_time'] ?? null;
        if (!$end) {
            return '';
        }

        $endDateTime     = new DateTime($end);
        $currentDateTime = new DateTime();
        if ($endDateTime < $currentDateTime) {
            return '';
        }

        $interval = $currentDateTime->diff($endDateTime);
        $days    = $interval->format('%a');
        $hours   = $interval->format('%H');
        $minutes = $interval->format('%I');

        return "⏳ Окончание песчаной бури через: {$days} дн. {$hours} ч. {$minutes} мин.";
    }

    /**
     * Уведомление: нет урона (на базе, без Gather/Explore)
     */
    protected function notifyNoDamage(array $character)
    {
        $tgUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];
        $message = "🌪 *Песчаная буря* не повлияла на тебя,\n"
            . "поскольку ты находишься на базе и не занят тяжелыми задачами.\n\n"
            . "_База — твоя лучшая защита!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        try {
            Request::sendMessage([
                'chat_id'     => $chatId,
                'text'        => $message,
                'parse_mode'  => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "notifyNoDamage: " . $e->getMessage());
        }
    }

    /**
     * Уведомляем о снижении выносливости (tiredDamage) и 1 атрибута на 0.01,
     * выводим, когда событие закончится (endTime).
     */
    protected function notifyDamage(
        array $character,
        float $tiredDamage,
        string $attributeReduced,
        float $reducedBy,
        string $endTime,
        bool $onBase
    ) {
        $tgUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $roundedDamage   = round($tiredDamage, 2);
        $roundedReduced  = round($reducedBy, 4);

        $message  = "🌪 *Песчаная буря* обрушилась на тебя!\n\n"
            . "Твоя выносливость снизилась на ~{$roundedDamage}.\n";
        if ($roundedReduced > 0) {
            $message .= "Дополнительно *{$attributeReduced}* уменьшен на {$roundedReduced}.\n";
        }
        if ($endTime) {
            $message .= "\n{$endTime}\n";
        }
        $message .= "\n🛑 Совет: если у тебя есть база, укройся там или дождись окончания шторма!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Картинка
        $photo = base_url('uploads/telegram/sand_storm.png');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "notifyDamage: " . $e->getMessage());
        }
    }
}
