<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    CharacterTaskModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

// Сервис с методами isCharacterOnBase, isGathering, isExploring
use App\Services\Player\PlayerStateService;

/**
 * Класс NightAttacksHandler
 *
 * Обрабатывает событие "NightAttacks" (Ночные нападения):
 * 1) С вероятностью 10% проверяется возможность события (90% пропуск).
 * 2) Проверяем, что текущее время (H:i) — «ночь» (в примере 20:00..05:00).
 * 3) Ищем событие NightAttacks в eventModel, проверяем activeEventModel->status='active'.
 * 4) Если активно, пробегаем всех персонажей:
 *    - если персонаж на базе и не занимается Gather/Explore => 0 урона.
 *    - иначе вычисляем урон на основе eventInfo['effect_value'],
 *      либо половину, если игрок просто «вне базы» без Gather/Explore.
 * 5) Уменьшаем health/tired, уведомляем игрока через Telegram.
 */
class NightAttacksHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterTaskModel;
    protected $telegramUserModel;
    protected $playerStateService;
    private   $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel     = new CharacterModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Подключаем сервис для проверок базы/задач
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram SDK
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод process():
     * 1) С шансом 10% (90% пропуск).
     * 2) Проверяем текущее время (пример: 20:00..05:00).
     * 3) Ищем событие 'NightAttacks' => проверяем status='active'.
     * 4) Для каждого персонажа вычисляем, есть ли урон.
     */
    public function process()
    {
        // 1) 10% шанс
        if (mt_rand(1, 100) > 10) {
            return; // 90% случаев не обрабатываем
        }

        // 2) Проверяем время: ночь = (20:00..23:59) или (00:00..05:00)
        $currentTime = date('H:i'); // формат "HH:ii"
        // Условие: если $currentTime >= '20:00' ИЛИ <= '05:00'
        if (!($currentTime >= '20:00' || $currentTime <= '05:00')) {
            // Если сейчас 06:00..19:59 => нет ночных нападений
            return;
        }

        // 3) Ищем событие NightAttacks
        $eventInfo = $this->eventModel
            ->where('name_english', 'NightAttacks')
            ->first();
        if (!$eventInfo) {
            return;
        }

        // Проверяем активность
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            return;
        }

        // 4) Пробегаем все персонажи
        $allCharacters = $this->characterModel->findAll();
        if (!$allCharacters) {
            return;
        }

        // Извлекаем effect_value (например, 50)
        $effectValue = $eventInfo['effect_value'] ?? 50;

        // Для каждого персонажа применяем урон (если нужно)
        foreach ($allCharacters as $character) {
            $this->applyNightAttack($character, $effectValue);
        }
    }

    /**
     * Применяем логику ночных нападений к одному персонажу:
     * 1) Если персонаж на базе и НЕ Gather/Explore => нет урона.
     * 2) Иначе:
     *    - если персонаж Gather/Explore => finalEffectPercent = effectValue (100%).
     *    - если просто вне базы => половина effectValue.
     * 3) Уменьшаем health/tired, уведомляем.
     */
    protected function applyNightAttack(array $character, float $effectValue)
    {
        $charId = $character['id'];

        // Проверка, на базе ли
        $onBase   = $this->playerStateService->isCharacterOnBase($charId);
        // Проверка, Gather/Explore
        $gather   = $this->playerStateService->isGathering($charId);
        $explore  = $this->playerStateService->isExploring($charId);

        if ($onBase && !$gather && !$explore) {
            // Нет урона, уведомляем
            $this->notifyNoDamage($character);
            return;
        }

        // Иначе: есть урон
        // Если персонаж Gather или Explore => 100% (effectValue)
        // Иначе => 50% от effectValue
        $finalEffectPercent = $effectValue;
        if (!$gather && !$explore) {
            // просто не на базе
            $finalEffectPercent = $effectValue * 0.5;
        }

        // Снижаем health/tired на finalEffectPercent%
        $healthDamage = $character['health'] * ($finalEffectPercent / 100.0);
        $tiredDamage  = $character['tired']  * ($finalEffectPercent / 100.0);

        $newHealth = max(0.01, $character['health'] - $healthDamage);
        $newTired  = max(0.01, $character['tired']  - $tiredDamage);

        // Сохраняем
        $this->characterModel->update($charId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Уведомляем
        $this->notifyDamage($character, $finalEffectPercent);
    }

    /**
     * Уведомление: персонаж на базе и не занят — нет урона.
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
        $message = "🌌 *Ночные нападения* не причинили тебе вреда.\n"
            . "Ты на базе и не занят, звери не могут пробраться!\n\n"
            . "_База — твоя спасительная крепость._";

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
            log_message('error', "Ошибка при отправке notifyNoDamage: " . $e->getMessage());
        }
    }

    /**
     * Уведомление о полученном уроне (finalEffectPercent).
     */
    protected function notifyDamage(array $character, float $damagePercent)
    {
        $tgUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];
        $roundedPercent = round($damagePercent, 2);

        $message  = "🌌 *Ночные нападения!* \n\n"
            . "Во время темноты звери/бандиты нанесли урон: ~ *{$roundedPercent}%* здоровья и выносливости.\n"
            . "Лучше укрыться на базе — там будешь в безопасности!\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Картинка (night attacks)
        $photoPath = base_url('uploads/telegram/night__attacks.png');

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photoPath),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyDamage: " . $e->getMessage());
        }
    }
}
