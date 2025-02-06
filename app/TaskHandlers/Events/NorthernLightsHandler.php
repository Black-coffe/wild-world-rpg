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

/**
 * Класс NorthernLightsHandler
 *
 * Обрабатывает событие "NorthernLights" (Северное сияние):
 * 1) С вероятностью 18% (82% пропуск) вызывается процесс.
 * 2) Проверяем, активно ли событие (в active_events).
 * 3) Ищем персонажей, чей biome_id входит в указанное в eventModel->biome_ids.
 * 4) Для каждого случайно выбираем boostType (experience, health, strength и т.д.),
 *    вычисляем boostValue, и увеличиваем соответствующее поле:
 *    - Для health/tired/gold — целое число (не выше 100).
 *    - Для experience/strength/agility/intellect — дробное (1..1.11).
 * 5) Отправляем уведомление игроку (телеграм) с описанием прироста.
 */
class NorthernLightsHandler extends Controller
{
    protected $characterModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $telegramUserModel;
    private   $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel    = new CharacterModel();
        $this->eventModel        = new EventModel();
        $this->activeEventModel  = new ActiveEventModel();
        $this->telegramUserModel = new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод:
     * 1) 18% шанс (82% пропуск).
     * 2) Проверяем наличие события 'NorthernLights' + его активность.
     * 3) Ищем characters, находящихся в biomes (из JSON).
     * 4) Для каждого: выбираем boostType, считаем boostValue, применяем.
     * 5) Уведомляем игрока.
     */
    public function process()
    {
        // 1) 18% вероятность
        if (mt_rand(0, 100) >= 18) {
            return; // ~82% случаев событие пропускаем
        }

        // 2) Ищем событие "NorthernLights"
        $eventInfo = $this->eventModel
            ->where('name_english', 'NorthernLights')
            ->first();
        if (!$eventInfo) {
            return; // Нет события
        }

        // Проверяем, активно ли (через ActiveEventModel->isActive)
        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return; // Неактивно
        }

        // 3) Список biomes, где действует событие
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (empty($biomeIds) || !is_array($biomeIds)) {
            return; // Нет биомов => выходим
        }

        // Находим персонажей по biome_id
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        foreach ($characters as $character) {
            // 4) Случайно выбираем тип буста
            $boostType = $this->selectBoostType();
            // Считаем значение буста
            $boostValue = $this->calculateBoostValue($boostType);

            // Применяем
            $this->applyBoost($character, $boostType, $boostValue);

            // 5) Уведомление
            $this->notifyCharacter($character, $boostType, $boostValue);
        }
    }

    /**
     * Случайный выбор одного из:
     * [experience, health, strength, agility, intellect, tired, gold]
     */
    protected function selectBoostType(): string
    {
        $types = ['experience', 'health', 'strength', 'agility', 'intellect', 'tired', 'gold'];
        return $types[array_rand($types)];
    }

    /**
     * Рассчитываем сам boost:
     * - если (experience/strength/agility/intellect) => random float [1..1.11]
     * - иначе (health/tired/gold) => random int [1..100]
     */
    protected function calculateBoostValue(string $type): float
    {
        if (in_array($type, ['experience', 'strength', 'agility', 'intellect'])) {
            // Дробное значение 1..1.11
            return rand(1, 111) / 100.0;
        }
        // Для health, tired, gold => int [1..100]
        return (float)rand(1, 100);
    }

    /**
     * Применяем буст к персонажу.
     * Для health/tired/gold не выходим за 100.
     * Для остальных — добавляем дробно.
     */
    protected function applyBoost(array $character, string $boostType, float $boostValue)
    {
        $charId  = $character['id'];
        $oldVal  = $character[$boostType] ?? 0;

        // Если буст health/tired/gold => целое, не выше 100
        if (in_array($boostType, ['health', 'tired', 'gold'])) {
            $boostValueInt = (int)$boostValue; // округляем
            // Если sum > 100 => обрезаем
            $sum = $oldVal + $boostValueInt;
            if ($sum > 100) {
                $boostValueInt = max(0, 100 - $oldVal);
            }
            if ($boostValueInt <= 0) {
                // Если персонаж уже на 100
                return;
            }
            // Обновляем
            $this->characterModel
                ->where('id', $charId)
                ->set($boostType, "$boostType + $boostValueInt", false)
                ->update();
        } else {
            // experience / strength / agility / intellect
            $newVal = $oldVal + $boostValue;
            // Можете добавить ограничения (например, max=999)
            $this->characterModel->update($charId, [
                $boostType => $newVal
            ]);
        }
    }

    /**
     * Уведомляем игрока в Telegram.
     */
    protected function notifyCharacter(array $character, string $boostType, float $boostValue)
    {
        // Ищем телеграм-пользователя
        $telegramUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'];
        if (!$chatId) {
            return;
        }

        // Формируем текст
        // Переводим boostType
        $boostNameRus = $this->translateBoostType($boostType);

        // Указываем boostValue (округлим при желании)
        $formattedValue = round($boostValue, 2);

        $message = "🌌 *Северное сияние!* \n\n"
            . "Твой персонаж получил прибавку к *{$boostNameRus}* на +{$formattedValue}.\n\n"
            . "_Полюбуйся прекрасным небом и используй полученный бонус с умом._";
        $message .= "\n\nПосмотри, сколько ещё длится событие, чтобы спланировать дальнейшие действия:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Фото (пример)
        $photo = base_url('uploads/telegram/beautiful_natural_phenomenon.png');

        // Отправка
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($photo),
                'caption' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке северного сияния: " . $e->getMessage());
        }
    }

    /**
     * Перевод типа (experience => 'опыт', health => 'здоровье', ...)
     */
    protected function translateBoostType(string $type): string
    {
        $map = [
            'experience' => 'опыту',
            'health'     => 'здоровью',
            'strength'   => 'силе',
            'agility'    => 'ловкости',
            'intellect'  => 'интеллекту',
            'tired'      => 'выносливости',
            'gold'       => 'золоту'
        ];
        return $map[$type] ?? $type;
    }
}
