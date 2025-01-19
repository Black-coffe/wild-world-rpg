<?php

namespace App\TaskHandlers\Events;

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

// Подключаем наш сервис
use App\Services\Player\PlayerStateService;

class SnowFallHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    protected $characterTaskModel;
    private   $telegram;

    protected $playerStateService; // сервис проверок

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();
        $this->characterTaskModel = new CharacterTaskModel();

        // Создаём сервис, где проверяем базу и т.д.
        $this->playerStateService = new PlayerStateService();

        // Telegram init
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        // 1) Вероятность 30%
        if (mt_rand(1, 100) > 30) {
            // В ~70% случаев событие не происходит
            return;
        }

        // 2) Ищем событие Snowfall
        $eventInfo = $this->eventModel
            ->where('name_english', 'Snowfall')
            ->first();
        if (!$eventInfo) {
            return;
        }

        // 3) Проверяем, активно ли
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return;
        }

        // 4) Берём biomes, на которых действует
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // 5) Ищем персонажей, кто в этих биомах
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        if (!$characters) {
            return;
        }

        // 6) Применяем эффект к тем, у кого есть активные задачи gather/explore
        foreach ($characters as $character) {
            // Старый код проверял activeTasks, но сейчас меняем логику:
            // Мы проверяем "на базе?" и "собирает?" и "изучает?" через PlayerStateService
            $this->applySnowfallEffects($character, $eventInfo);
        }
    }

    protected function applySnowfallEffects(array $character, array $eventInfo)
    {
        $charId = $character['id'];

        // 1) Если на базе => урон не наносим, просто уведомление
        $onBase      = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering = $this->playerStateService->isGathering($charId);
        $isExploring = $this->playerStateService->isExploring($charId);

        if ($onBase && !$isGathering && !$isExploring) {
            // Сообщаем "Снежный обвал, но база защитила"
            $this->notifySafeOnBase($character);
            return;
        }

        // 2) Если не на базе:
        //    - gather || explore => 100%
        //    - иначе => 70%
        $damageRatio = 1.0;
        if (!$isGathering && !$isExploring) {
            $damageRatio = 0.70;
        }

        // 3) Определяем, по какому параметру удар (health или tired).
        //    И сколько (1..90, как в оригинальном коде).
        $damageType   = rand(0, 1) ? 'health' : 'tired';
        $damageAmount = rand(1, 90);

        // Уменьшаем "damageAmount" с учётом damageRatio
        $actualDamage = round($damageAmount * $damageRatio);

        // 4) Считаем новое значение, не ниже 0.01
        $oldValue = $character[$damageType];
        $newValue = max(0.01, $oldValue - $actualDamage);

        // Сохраняем
        $this->characterModel->update($charId, [
            $damageType => $newValue
        ]);

        // 5) Уведомляем
        $this->notifyDamage($character, $damageType, $actualDamage, $damageRatio);
    }

    /**
     * Уведомление, если персонаж на базе и не получает урона
     */
    protected function notifySafeOnBase(array $character)
    {
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "❄️ *Снежный обвал* оказался не страшен!\n"
            . "Ты находишься на своей базе, которая надёжно защищает тебя от стихии.\n\n"
            . "_Всегда помни о безопасности и укрепляй свою базу!_";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
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
            log_message('error', "Ошибка при отправке notifySafeOnBase: " . $e->getMessage());
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
        $tgUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $typeText = ($damageType === 'health') ? 'здоровье' : 'выносливость';
        $ratioText = ($damageRatio >= 1.0)
            ? "Полная сила удара (100%)"
            : "Ослабленный урон (70%)";

        $message  = "⚠️ *Снежный обвал* обрушился на тебя!\n\n";
        $message .= "❄️ Потеряно *{$actualDamage}* единиц {$typeText}.\n";
        $message .= "({$ratioText})\n\n";
        $message .= "🗻 *Совет*: спасись в биоме, не затронутом обвалом, или вернись на свою базу — там будешь в безопасности.\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        $photoPath = base_url('uploads/telegram/heavy_snowfalls_cause_avalanches_and_snowfalls.png');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photoPath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyDamage: " . $e->getMessage());
        }
    }
}
