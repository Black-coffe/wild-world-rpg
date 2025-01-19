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

// Подключаем сервис, где лежат методы isCharacterOnBase, isGathering, isExploring
use App\Services\Player\PlayerStateService;

class NightAttacksHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $characterTaskModel;
    protected $telegramUserModel;
    protected $playerStateService; // Для проверок базы, задач
    private   $telegram;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Создаём сервис для проверок
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    public function process()
    {
        // 1) Проверяем 10% шанс
        if (mt_rand(1, 100) > 10) {
            return; // 90% случаев — событие не происходит
        }

        // 2) Проверяем, что время примерно ночь (20:00..05:00)
        $currentTime = date('H:i');
        if (!($currentTime >= '20:00' || $currentTime <= '05:00')) {
            log_message('error', 'Ночные нападения не срабатывают, т.к. не ночь');
            return;
        }

        // 3) Ищем событие NightAttacks
        $eventInfo = $this->eventModel
            ->where('name_english', 'NightAttacks')
            ->first();
        if (!$eventInfo) {
            log_message('error', 'Event NightAttacks not found');
            return;
        }

        // 4) Проверяем, активно ли оно
        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();
        if (!$activeEvent) {
            log_message('error', 'Active NightAttacks event not found');
            return;
        }

        // 5) Получаем всех персонажей
        $allCharacters = $this->characterModel->findAll();
        if (!$allCharacters) {
            return;
        }

        // 6) Пробегаемся по каждому персонажу
        foreach ($allCharacters as $character) {
            // Допустим, effect_value = 50 (значит 50% урона)
            $effectValue = $eventInfo['effect_value'] ?? 50;

            // Определяем, есть ли у персонажа активные задачи, которые интересуют нас
            // (возможно, надо проверить Gather/Explore, но ниже мы используем сервис)
            $this->applyNightAttack($character, $effectValue);
        }
    }

    protected function applyNightAttack(array $character, float $effectValue)
    {
        $charId = $character['id'];

        // Если игрок на базе + не собирает/не изучает => защита 100% (урон=0)
        $onBase   = $this->playerStateService->isCharacterOnBase($charId);
        $gather   = $this->playerStateService->isGathering($charId);
        $explore  = $this->playerStateService->isExploring($charId);

        if ($onBase && !$gather && !$explore) {
            // Уведомляем, что звери не тронули
            $this->notifyNoDamage($character);
            return;
        }

        // Иначе смотрим, сколько % урона (50% или 100%)
        // - если gather или explore => 100% (effectValue = 50)
        // - иначе => 50% от effectValue
        $finalEffectPercent = $effectValue;
        if (!$gather && !$explore) {
            // не на базе, не занят => половина
            $finalEffectPercent = $effectValue * 0.5;
        }

        // Снижаем здоровье/выносливость на finalEffectPercent% от текущего
        $healthDamage = $character['health'] * ($finalEffectPercent / 100.0);
        $tiredDamage  = $character['tired']  * ($finalEffectPercent / 100.0);

        $newHealth = max(1, $character['health'] - $healthDamage);
        $newTired  = max(1, $character['tired']  - $tiredDamage);

        // Обновляем
        $this->characterModel->update($charId, [
            'health' => $newHealth,
            'tired'  => $newTired,
        ]);

        // Шлём уведомление
        $this->notifyDamage($character, $finalEffectPercent);
    }

    /**
     * Уведомляем, что персонаж не получил урона.
     */
    protected function notifyNoDamage(array $character)
    {
        $tgUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];
        $message = "🌌 *Ночные нападения* не причинили тебе вреда.\n"
            . "Ты находишься на своей базе и не занят делами, звери не могут пробраться!\n\n"
            . "_Всегда помни: база — твоя крепость._";

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
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
                'reply_markup'=> json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyNoDamage: " . $e->getMessage());
        }
    }

    /**
     * Уведомляем о полученном уроне (finalEffectPercent).
     */
    protected function notifyDamage(array $character, float $damagePercent)
    {
        $tgUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$tgUser) {
            return;
        }

        $chatId = $tgUser['telegram_id'];
        // Округлим
        $roundedPercent = round($damagePercent, 2);

        $message  = "🌌 *Ночные нападения!* \n\n";
        $message .= "Звери (или бандиты) нанесли урон: ~ *{$roundedPercent}%* здоровья/выносливости.\n";
        $message .= "Совет: можешь укрыться на базе, если у тебя она есть — там звери не смогут напасть!\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️','callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Допустим, есть картинка
        $photoPath = base_url('uploads/telegram/night__attacks.png');

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
