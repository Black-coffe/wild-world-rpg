<?php

namespace App\TaskHandlers\Events;

use DateTime;
use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel,
    CharacterTaskModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Подключаем наш сервис (проверка базы, Gather, Explore)
use App\Services\Player\PlayerStateService;

/**
 * Класс SnowFallHandler
 *
 * Обрабатывает событие "Snowfall" (Снежный обвал):
 * 1) С вероятностью 30% (70% случаев пропуска).
 * 2) Проверяем активность события (activeEventModel, status='active').
 * 3) Для biomes (из eventModel->biome_ids) выбираем персонажей (CharacterModel->whereIn).
 * 4) Для каждого персонажа:
 *   - если на базе и не Gather/Explore => урона нет (notifySafeOnBase).
 *   - иначе рассчитываем урон (случайно бьёт по здоровью ИЛИ по выносливости, 1..90),
 *     учитываем общий damageRatio (100% или 70%), затем уведомляем обвальном уроне.
 */
class SnowFallHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    private   $telegram;

    // Сервис для проверок (isCharacterOnBase, isGathering, isExploring)
    protected $playerStateService;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel     = new CharacterModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();

        // Сервис проверок
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) 30% шанс (70% skip).
     * 2) Ищем событие Snowfall => проверяем, активно ли.
     * 3) Список biome_ids => у кого biome_id там, обрабатываем applySnowfallEffects().
     */
    public function process()
    {
        // 1) 30% вероятность
        if (mt_rand(1, 100) > 30) {
            // ~70% случаев не обрабатываем
            return;
        }

        // 2) Ищем событие Snowfall
        $eventInfo = $this->eventModel
            ->where('name_english', 'Snowfall')
            ->first();
        if (!$eventInfo) {
            return;
        }

        // Проверяем, активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return;
        }

        // 3) biomes
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // Находим персонажей
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        if (!$characters) {
            return;
        }

        // 4) Для каждого — вызов applySnowfallEffects
        foreach ($characters as $character) {
            $this->applySnowfallEffects($character, $eventInfo);
        }
    }

    /**
     * Применяем "снежный обвал" к персонажу:
     * - Если на базе и не Gather/Explore => no damage
     * - Иначе бьём либо health, либо tired (rand(1..90)),
     *   умножая на 1.0 (100%) или 0.70 (70%) при отсутствии Gather/Explore,
     *   notifyDamage
     */
    protected function applySnowfallEffects(array $character, array $eventInfo)
    {
        $charId = $character['id'];

        // Проверки базы и задач
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        if ($onBase && !$isGathering && !$isExploring) {
            // База защищает
            $this->notifySafeOnBase($character);
            return;
        }

        // damageRatio
        $damageRatio = 1.0; // если gather||explore
        if (!$isGathering && !$isExploring) {
            $damageRatio = 0.70; // иначе 70%
        }

        // Выбираем: бьём по 'health' или 'tired'
        $damageType = (mt_rand(0,1) === 0) ? 'health' : 'tired';
        // Случайно 1..90
        $damageAmount = mt_rand(1, 90);
        // Умножаем на ratio
        $actualDamage = round($damageAmount * $damageRatio);

        // Уменьшаем
        $oldVal = $character[$damageType];
        $newVal = max(0.01, $oldVal - $actualDamage);

        $this->characterModel->update($charId, [
            $damageType => $newVal
        ]);

        // Уведомляем
        $this->notifyDamage($character, $damageType, $actualDamage, $damageRatio);
    }

    /**
     * Уведомление, если персонаж на базе (без Gather/Explore)
     */
    protected function notifySafeOnBase(array $character)
    {
        $tgUser = $this->telegramUserModel
            ->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];

        $message = "❄️ *Снежный обвал* не страшен!\n"
            . "Ты находишься в своей базе и не занят рискованными задачами.\n"
            . "_База — твоя крепость в суровых условиях._";

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
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при notifySafeOnBase: " . $e->getMessage());
        }
    }

    /**
     * Уведомление об уроне
     */
    protected function notifyDamage(
        array $character,
        string $damageType,
        int $actualDamage,
        float $damageRatio
    ) {
        $tgUser = $this->telegramUserModel
            ->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $typeText = ($damageType === 'health') ? 'здоровье' : 'выносливость';
        // ratio: 1.0 => "полный урон", 0.7 => "70%"
        $ratioTxt = ($damageRatio >= 1.0) ? "полной силой (100%)" : "частично (70%)";

        $message  = "⚠️ *Снежный обвал!* \n\n";
        $message .= "Ты потерял *{$actualDamage}* единиц {$typeText}, \n"
            . "так как тебя задел обвал {$ratioTxt}.\n\n"
            . "☃️ Лучше переместиться в безопасный биом или на базу, пока ситуация не ухудшилась!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Допустим, есть картинка
        $photoPath = base_url('uploads/telegram/heavy_snowfalls_cause_avalanches_and_snowfalls.png');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photoPath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при notifyDamage: " . $e->getMessage());
        }
    }
}
