<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\ActiveEventModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс GeothermalSpringsHandler
 *
 * Обрабатывает событие "GeothermalFountains" (Геотермальные фонтаны):
 * 1) С вероятностью ~15% (при вызове process()) проверяется активность события в active_events.
 * 2) Если событие активно, находит всех персонажей, находящихся в указанных биомах (biome_ids).
 * 3) Каждый персонаж получает небольшой буст (к здоровью или выносливости на 1..10),
 *    но не выше 100.
 * 4) Уведомляет игрока через Telegram (с картинкой).
 */
class GeothermalSpringsHandler extends Controller
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
        $this->characterModel   = new CharacterModel();
        $this->eventModel       = new EventModel();
        $this->activeEventModel = new ActiveEventModel();
        $this->telegramUserModel= new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод обработки.
     * 1) Случайная проверка 15% (если mt_rand > 15 => ничего не делаем).
     * 2) Проверяем, активно ли "GeothermalFountains" (в active_events).
     * 3) Ищем biomes из event'а (biome_ids).
     * 4) Для каждого персонажа в этих биомах восстанавливаем либо health, либо tired на 1..10.
     */
    public function process()
    {
        // 1) Вероятность ~15% (85% случаев "пропуск")
        if (mt_rand(1, 100) > 15) {
            return;
        }

        // 2) Событие "GeothermalFountains" из таблицы events
        $eventInfo = $this->eventModel
            ->where('name_english', 'GeothermalFountains')
            ->first();
        if (!$eventInfo) {
            // Нет записи события => завершаем
            return;
        }

        // 2a) Проверяем, активно ли оно сейчас
        if (!$this->activeEventModel->isActive($eventInfo['event_id'])) {
            return;
        }

        // 3) Получаем, какие biomes участвуют
        $biomeIds = json_decode($eventInfo['biome_ids'], true);
        if (!is_array($biomeIds)) {
            return;
        }

        // 4) Ищем всех персонажей, biome_id которых в списке
        $characters = $this->characterModel
            ->whereIn('biome_id', $biomeIds)
            ->findAll();

        // 5) Применяем исцеление к каждому
        foreach ($characters as $character) {
            $this->applyHealing($character);
        }
    }

    /**
     * Применяет исцеление к персонажу:
     * - healAmount = 1..10
     * - Случайно восстанавливаем либо health, либо tired
     * - Не поднимаем свыше 100
     */
    protected function applyHealing(array $character)
    {
        // 1..10
        $healAmount = rand(1, 10);

        // Случайно выбираем, что лечим: health (true) или tired (false)
        // Можно было бы использовать mt_rand(0, 1) или что-то подобное
        $healedProperty = rand(0, 1) ? 'health' : 'tired';

        // Не превышаем 100
        $currentValue = (float) $character[$healedProperty];
        $maxCap       = 100.0;

        // Если текущее + healAmount > 100, обрезаем
        if ($currentValue + $healAmount > $maxCap) {
            $healAmount = $maxCap - $currentValue; // остаток до 100
        }

        // Если healAmount стал <= 0, значит уже 100, лечить не нужно
        if ($healAmount <= 0) {
            return;
        }

        // Обновляем таблицу characters
        $newValue = $currentValue + $healAmount;
        $this->characterModel->update($character['id'], [
            $healedProperty => $newValue
        ]);

        // Уведомляем игрока
        $this->notifyCharacter($character, $healedProperty, $healAmount);
    }

    /**
     * Отправка сообщения о том, что персонаж получил +X к здоровью/выносливости.
     */
    protected function notifyCharacter(array $character, string $boostType, float $boostValue)
    {
        // Получаем запись TelegramUser (из telegram_users)
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

        // Переводим 'health'/'tired' в строку
        $boostTypeText = ($boostType === 'health')
            ? 'здоровье'
            : 'выносливость';

        // Сообщение
        $message = sprintf(
            "🌋 *Благодаря событию 'Геотермальные фонтаны' твой персонаж восстановил %s на +%.0f.*\n\n" .
            "_Почувствуй энергию и тепло природного источника!_",
            $boostTypeText,
            $boostValue
        );

        $message .= "\n\nПосмотри, сколько ещё продлится событие, и планируй дальнейшие действия 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Указываем путь к картинке (пример)
        $photo = base_url('uploads/telegram/geothermal__fountains.png');

        // Отправка фото + caption
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке сообщения GeothermalFountains: " . $e->getMessage());
        }
    }
}
