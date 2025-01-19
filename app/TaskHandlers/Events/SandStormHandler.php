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

// Подключаем сервис
use App\Services\Player\PlayerStateService;

class SandStormHandler extends Controller
{
    protected $characterModel;
    protected $biomeModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private   $telegram;

    // Наш сервис проверок
    protected $playerStateService;

    public function __construct()
    {
        $this->characterModel     = new CharacterModel();
        $this->biomeModel         = new BiomeModel();
        $this->eventModel         = new EventModel();
        $this->activeEventModel   = new ActiveEventModel();
        $this->telegramUserModel  = new TelegramUserModel();

        // Инициализируем сервис проверок
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
        // 1) Повышаем вероятность до ~25%
        if (mt_rand(1, 100) > 25) {
            return; // 75% случаев пропускаем
        }

        // 2) Проверяем, активно ли событие "Sandstorm"
        $activeEvent = $this->isEventActive('Sandstorm');
        if (!$activeEvent) {
            return; // Неактивно, выходим
        }

        // 3) Проверяем наличие biome_ids
        if (!isset($activeEvent['biome_ids'])) {
            return; // Нет биомов в списке
        }

        $affectedBiomes = json_decode($activeEvent['biome_ids'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return; // Ошибка JSON
        }

        // 4) Проходим по биомам → находим персонажей → применяем урон
        foreach ($affectedBiomes as $biomeId) {
            $charactersInBiome = $this->characterModel
                ->where('biome_id', $biomeId)
                ->findAll();

            foreach ($charactersInBiome as $character) {
                // Урон применяем в любом случае, если в старом коде
                // была проверка "не заняты", а сейчас изменим:
                $this->applySandStormEffects($character, $activeEvent);
            }
        }
    }

    /**
     * Проверяем, есть ли активное событие Sandstorm, возвращаем массив или false.
     */
    protected function isEventActive(string $eventNameEnglish)
    {
        $eventInfo = $this->eventModel->where('name_english', $eventNameEnglish)->first();

        if (!$eventInfo || empty($eventInfo['biome_ids'])) {
            return false;  // Событие не найдено / неверные данные
        }

        $activeEvent = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if ($activeEvent) {
            // Добавляем поля, если хотим
            $activeEvent['biome_ids']   = $eventInfo['biome_ids'];
            $activeEvent['effect_value']= $eventInfo['effect_value'];
            return $activeEvent;
        }
        return false;
    }

    /**
     * Применяем логику песчаной бури к конкретному персонажу
     * с учётом проверок базы и занятости.
     */
    protected function applySandStormEffects(array $character, array $activeEvent)
    {
        // Если у персонажа усталость уже 0.01, не трогаем
        if ($character['tired'] <= 0.01) {
            return;
        }

        // Ищем effect_value (например 30)
        $effectValue = $activeEvent['effect_value'] ?? 30;

        // Проверяем, на базе ли он (isCharacterOnBase)
        // и занятость (Gather/Explore)
        $charId        = $character['id'];
        $onBase        = $this->playerStateService->isCharacterOnBase($charId);
        $isGathering   = $this->playerStateService->isGathering($charId);
        $isExploring   = $this->playerStateService->isExploring($charId);

        // 1) Если на базе и не собирает/не изучает → вреда нет
        if ($onBase && !$isGathering && !$isExploring) {
            $this->notifyNoDamage($character);
            return;
        }

        // 2) Если не на базе:
        //    - если (gather||explore), урон 100%
        //    - иначе (не gather, не explore) => урон 80%
        $damageRatio = 1.0; // 100%
        if (!$isGathering && !$isExploring) {
            $damageRatio = 0.8; // 80%
        }

        // Расчитываем урон
        $tiredDamage = $this->calculateTiredDamage($character, $effectValue) * $damageRatio;
        $tiredDamage = round($tiredDamage, 2);

        // Уменьшаем tired
        $newTired = max(0.01, $character['tired'] - $tiredDamage);

        // Рандомно уменьшаем один из атрибутов (если > 0.01)
        $attributes = ['experience', 'strength', 'agility', 'intellect'];
        $attributeToReduce = $attributes[array_rand($attributes)];
        $attrVal = $character[$attributeToReduce];
        $reducedBy = 0.0;
        if ($attrVal > 0.01) {
            // допустим уменьшаем на 0.01
            $reducedBy = 0.01;
            $attrVal = max(0.01, $attrVal - $reducedBy);
        }

        // Сохраняем
        $this->characterModel->update($charId, [
            'tired' => $newTired,
            $attributeToReduce => $attrVal,
        ]);

        // Уведомляем
        $endTime = $this->formatEventEndTime($activeEvent);
        $this->notifyDamage($character, $tiredDamage, $attributeToReduce, $reducedBy, $endTime, $onBase);
    }

    /**
     * Формула урона по усталости, аналогична старой calculateTiredDamage
     */
    protected function calculateTiredDamage(array $character, float $effectValue): float
    {
        // Допустим, берём уровень, danger_level...
        // Для упрощения используем что-то вроде:

        $biome = $this->biomeModel->find($character['biome_id']);
        if (!$biome) {
            return $effectValue; // если нет биома
        }

        $charLevel   = $character['level'] ?? 1;
        $danger      = $biome['danger_level']        ?? 5;
        $difficulty  = $biome['survival_difficulty'] ?? 5;

        $levelFactor = max(1, 100 - $charLevel) / 100;
        $biomeFactor = ($danger + $difficulty) / 20;
        $randomFactor= rand(50, 150) / 100.0; // ±50%

        $damage = $effectValue * $levelFactor * $biomeFactor * $randomFactor;
        return round($damage, 2);
    }

    protected function formatEventEndTime(array $activeEvent)
    {
        $end = $activeEvent['end_time'] ?? null;
        if (!$end) {
            return '';
        }

        $endDateTime     = new DateTime($end);
        $currentDateTime = new DateTime();
        $interval = $currentDateTime->diff($endDateTime);

        $days    = $interval->format('%a');
        $hours   = $interval->format('%H');
        $minutes = $interval->format('%I');

        return "⏳ Событие закончится через: {$days} дн. {$hours} чс. {$minutes} мин.";
    }

    /**
     * Игрок на базе и не занят → урон не наносится
     */
    protected function notifyNoDamage(array $character)
    {
        $tgUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $message = "🌪 *Песчаная буря* пронеслась, но ты находишься на своей базе и не занят тяжёлыми делами.\n"
            . "Ты полностью защищён от бури!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',         'callback_data' => 'events']
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
     * Игрок получил урон (вне базы, или занят)
     */
    protected function notifyDamage(
        array $character,
        float $tiredDamage,
        string $attributeReduced,
        float $reducedBy,
        string $endTime,
        bool $wasOnBase
    ) {
        $tgUser = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first();
        if (!$tgUser) {
            return;
        }
        $chatId = $tgUser['telegram_id'];

        $roundedDamage = round($tiredDamage, 2);
        $roundedAttr   = round($reducedBy, 4);

        // Если onBase=false, либо занят => получаем урон
        $message  = "🌪 *Песчаная буря* нанесла тебе урон!\n\n";
        $message .= "Твоя *выносливость* снизилась на ~{$roundedDamage}.\n";
        if ($roundedAttr > 0) {
            $message .= "Также *{$attributeReduced}* уменьшен на {$roundedAttr}.\n";
        }
        $message .= "{$endTime}\n\n";
        $message .= "🛑 Совет: укройся на базе (если есть) или в другом безопасном биоме,\n"
            . "иначе буря может нанести ещё больший урон!";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',         'callback_data' => 'events']
                ]
            ]
        ];

        $photo = base_url('uploads/telegram/sand_storm.png'); // Картинка бури

        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке notifyDamage: " . $e->getMessage());
        }
    }
}
