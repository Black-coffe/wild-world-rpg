<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс ShootingStarHandler
 *
 * Обрабатывает событие "Starfall" (Звездопад):
 * 1) С вероятностью 20% при каждом вызове process() (80% случаев пропускаем).
 * 2) Проверяем, активно ли событие (в ActiveEventModel, status='active').
 * 3) Извлекаем biomes (biome_ids) и находим всех персонажей, чьи biome_id в этом списке.
 * 4) Для каждого персонажа выбираем случайный boostType (experience, health, strength, agility, intellect, tired, gold).
 * 5) Рассчитываем boostValue:
 *    - если это один из [experience, strength, agility, intellect], берём дробное rand(1..111)/100 (1.00..1.11).
 *    - иначе (health/tired/gold), берём целое rand(1..100), и ограничиваем, чтобы не превысить 100.
 * 6) Применяем повышение к персонажу (health/tired/gold <=100, остальное — прибавляем как есть).
 * 7) Уведомляем персонажа через Telegram (с фото и описанием).
 */
class ShootingStarHandler extends Controller
{
    /** @var CharacterModel */
    protected $characterModel;
    /** @var EventModel */
    protected $eventModel;
    /** @var ActiveEventModel */
    protected $activeEventModel;
    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var Telegram */
    private $telegram;

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
     * Основной метод process:
     * 1) 20% шанс (80% пропуск).
     * 2) Проверяем, что событие "Starfall" (Звездопад) активно.
     * 3) Список biome_ids из eventInfo.
     * 4) Для каждого персонажа в этих biomes:
     *    - выбираем boostType,
     *    - считаем boostValue,
     *    - применяем (с учётом ограничений),
     *    - уведомляем игрока.
     */
    public function process()
    {
        // 1) Если rand>=20 => 80% случаев пропускаем
        if (mt_rand(0, 100) >= 20) {
            return;
        }

        // 2) Ищем событие 'Starfall'
        $eventInfo = $this->eventModel
            ->where('name_english', 'Starfall')
            ->first();
        if (!$eventInfo) {
            return; // Событие не найдено
        }

        // Проверка активности
        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return; // Неактивно
        }

        // 3) Список биомов
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds) || empty($biomeIds)) {
            return;
        }

        // Ищем персонажей, у которых biome_id в списке
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 4) Для каждого персонажа
        foreach ($characters as $character) {
            // Выбираем случайный boostType
            $boostType  = $this->selectBoostType();
            // Считаем значение
            $boostValue = $this->calculateBoostValue($boostType);

            // Применяем
            if (in_array($boostType, ['health', 'tired', 'gold'])) {
                // Округляем и ограничиваем до 100
                $intValue = (int) $boostValue;
                $currentVal = $character[$boostType] ?? 0;
                $sum = $currentVal + $intValue;
                if ($sum > 100) {
                    $intValue = max(0, 100 - $currentVal);
                }
                if ($intValue <= 0) {
                    // уже 100, нет смысла
                    continue;
                }
                // Увеличиваем
                $newVal = $currentVal + $intValue;
                $this->characterModel->update($character['id'], [
                    $boostType => $newVal
                ]);
            } else {
                // experience/strength/agility/intellect
                $oldVal = $character[$boostType] ?? 0;
                $newVal = $oldVal + $boostValue;
                // При желании ограничиваем, например <999
                $this->characterModel->update($character['id'], [
                    $boostType => $newVal
                ]);
            }

            // Уведомляем
            $this->notifyCharacter($character, $boostType, $boostValue);
        }
    }

    /**
     * Выбор одного из:
     * [experience, health, strength, agility, intellect, tired, gold]
     */
    protected function selectBoostType(): string
    {
        $types = ['experience','health','strength','agility','intellect','tired','gold'];
        return $types[array_rand($types)];
    }

    /**
     * Рассчитываем boostValue:
     *  - Если experience/strength/agility/intellect => rand(1..111)/100 (1.00..1.11)
     *  - Иначе (health/tired/gold) => rand(1..100)
     */
    protected function calculateBoostValue(string $type): float
    {
        if (in_array($type, ['experience','strength','agility','intellect'])) {
            return rand(1, 111) / 100.0;
        }
        // health, tired, gold => целое [1..100]
        return (float)rand(1, 100);
    }

    /**
     * Уведомляем игрока через Telegram:
     * выводим, какой параметр и насколько повысился.
     */
    protected function notifyCharacter(array $character, string $boostType, float $boostValue)
    {
        // Ищем запись Telegram-пользователя
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

        // Переводим boostType (experience => 'опыт', 'health' => 'здоровье', ...)
        $translatedType = $this->translateBoostType($boostType);
        // Округлим
        $roundedVal = round($boostValue, 2);

        $message = "🌌 *Звездопад!* \n\n"
            . "Сияющие звёзды подарили вашему персонажу прибавку:\n"
            . "*+{$roundedVal}* к {$translatedType}.\n\n"
            . "_Наслаждайтесь этим даром небес и используйте его с умом!_\n";

        $message .= "\nПосмотрите, сколько ещё продлится событие, чтобы спланировать дальнейшие действия:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж',       'callback_data' => 'character'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // Путь к изображению
        $photoPath = base_url('uploads/telegram/hundreds_of_shooting_stars.png');

        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photoPath),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке ShootingStarHandler: " . $e->getMessage());
        }
    }

    /**
     * Перевод типа:
     * experience => 'опыт'
     * health => 'здоровье'
     * ...
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
        return $map[$type] ?? 'неизвестному параметру';
    }
}
