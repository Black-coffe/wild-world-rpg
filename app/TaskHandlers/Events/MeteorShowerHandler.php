<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    BiomeModel,
    CharacterResourceModel,
    EventModel,
    ActiveEventModel,
    TelegramUserModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс MeteorShowerHandler
 *
 * Обрабатывает событие "MeteorRain" (Метеоритный дождь):
 * 1) Проверяет, активно ли событие (active_events, end_time >= now).
 * 2) Случайно выбирает 1000 игровых ячеек (cell_number) —
 *    можно настроить количество по вашему желанию (50, 100, 1000 и т.д.).
 * 3) По этим ячейкам ищет персонажей (CharacterModel->cell_number).
 * 4) Для каждого персонажа случайно решает (50/50), наносим ли урон *ресурсам* (ResourceDamage) или *персональный* (PersonalDamage).
 * 5) В случае урона ресурсам — сокращаем каждый ресурс на effect_value% (но минимум 1 единицу оставляем).
 * 6) В случае урона персонажу — понижаем health и tired на effect_value% (но минимум 0.01).
 * 7) Уведомляем игрока через Telegram с фото и пояснением.
 */
class MeteorShowerHandler extends Controller
{
    /** @var CharacterModel */
    protected $characterModel;
    /** @var BiomeModel */
    protected $biomeModel;
    /** @var CharacterResourceModel */
    protected $characterResourceModel;
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
        $this->characterModel        = new CharacterModel();
        $this->biomeModel            = new BiomeModel();
        $this->characterResourceModel= new CharacterResourceModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->telegramUserModel     = new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Главный метод process():
     * 1) Находим "MeteorRain" в eventModel.
     * 2) Проверяем активен ли (end_time >= now, status='active').
     * 3) Случайно выбираем 1000 ячеек (cell_number).
     * 4) Ищем персонажей, у которых cell_number входит в выбранные ячейки.
     * 5) Для каждого: 50% урон ресурсам / 50% урон персонажу.
     */
    public function process()
    {
        // 1) Получаем данные о событии "MeteorRain"
        $meteorEvent = $this->eventModel
            ->where('name_english', 'MeteorRain')
            ->first();
        if (!$meteorEvent) {
            log_message('error', "Событие 'Метеоритный дождь' (MeteorRain) не найдено.");
            return;
        }

        // 2) Проверяем, активно ли событие (status='active', end_time >= now)
        $isActive = $this->activeEventModel
            ->where('event_id', $meteorEvent['event_id'])
            ->where('status', 'active')
            ->where('end_time >=', date('Y-m-d H:i:s')) // убеждаемся, что оно не закончилось
            ->first();

        if (!$isActive) {
            return; // событие неактивно — выходим
        }

        // 3) Случайно выбираем N ячеек (здесь 1000)
        //    По геймдизайну можно варьировать количество
        $selectedCells = $this->selectRandomCells(1000);

        // Примерно: если хотите тестировать конкретную ячейку:
        // $selectedCells[] = 56451; // раскомментировать при отладке

        // 4) Ищем персонажей, у которых cell_number в $selectedCells
        $affectedCharacters = $this->characterModel
            ->whereIn('cell_number', $selectedCells)
            ->findAll();

        // 5) Для каждого персонажа:
        //    - random(0,1) => 0 => урезаем ресурсы, 1 => урезаем health/tired
        foreach ($affectedCharacters as $character) {
            if (mt_rand(0, 1) === 0) {
                // Ресурсы
                $this->applyResourceDamage($character, $meteorEvent);
            } else {
                // Персональный урон
                $this->applyPersonalDamage($character, $meteorEvent);
            }
        }
    }

    /**
     * Формирует массив случайных cell_number.
     * По логике, cell_number может быть от 1 до 1,000,000 (в примере).
     *
     * @param int $count сколько ячеек выбрать
     * @return array список случайных cell_number
     */
    protected function selectRandomCells(int $count): array
    {
        $cells = [];
        for ($i = 0; $i < $count; $i++) {
            // Допустим, карта у нас max 1,000,000 ячеек
            $cells[] = mt_rand(1, 1000000);
        }
        return $cells;
    }

    /**
     * Уменьшаем ресурсы персонажа на effect_value% (но минимум 1 оставляем).
     */
    protected function applyResourceDamage(array $character, array $eventInfo)
    {
        // Получаем все ресурсы персонажа
        $resources = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->findAll();

        // effect_value: % сколько срезаем
        $effectValue = $eventInfo['effect_value'];

        foreach ($resources as $resource) {
            // Стараемся "срезать" effectValue%
            $oldQty   = (int) $resource['quantity'];
            if ($oldQty <= 1) {
                // Если уже 1, оставим как есть
                continue;
            }

            $damageQty= (int) ceil($oldQty * ($effectValue / 100.0));
            $newQty   = $oldQty - $damageQty;
            if ($newQty < 1) {
                $newQty = 1;
            }

            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $newQty
            ]);
        }

        // Уведомляем игрока
        $this->notifyCharacter($character, "resource damage", $effectValue);
    }

    /**
     * Уменьшаем health/tired персонажа на effect_value%
     * (но минимум 0.01).
     */
    protected function applyPersonalDamage(array $character, array $eventInfo)
    {
        $effectValue = $eventInfo['effect_value'];

        // health -> health - (health * effectValue/100)
        // tired  -> tired  - (tired  * effectValue/100)

        $oldHealth   = $character['health'];
        $oldTired    = $character['tired'];

        $damageHealth= $oldHealth * ($effectValue / 100.0);
        $damageTired = $oldTired  * ($effectValue / 100.0);

        $newHealth   = max(0.01, $oldHealth - $damageHealth);
        $newTired    = max(0.01, $oldTired  - $damageTired);

        $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'tired'  => $newTired
        ]);

        // Уведомление
        $this->notifyCharacter($character, "personal damage", $effectValue);
    }

    /**
     * Отправляем игроку сообщение в Telegram:
     * - Если $type == "resource damage": ресурсы срезаны
     * - Иначе персональный урон (health/tired)
     */
    protected function notifyCharacter(array $character, string $type, float $effectValue)
    {
        // Находим телеграм-пользователя
        $telegramUser = $this->telegramUserModel
            ->where('id', $character['telegram_user_id'])
            ->first();
        if (!$telegramUser) {
            return; // Выходим, если нет
        }

        $chatId = $telegramUser['telegram_id'];
        if (!$chatId) {
            return;
        }

        // Формируем текст
        $message = "⚠️ *Метеоритный дождь* повлиял на вашего персонажа!\n\n";

        if ($type === "resource damage") {
            $message .= "• Ваши *ресурсы* сокращены примерно *на {$effectValue}%* в результате падения метеорита.\n";
        } else {
            $message .= "• Ваше *здоровье и выносливость* снижены *на {$effectValue}%* из-за удара метеорита.\n";
        }

        $message .= "\n_Не забудьте укрыться или использовать защитные сооружения, если они у вас есть!_\n";
        $message .= "Посмотрите, сколько ещё длится событие, чтобы принять нужные меры 👇";

        // Inline-клавиатура
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        // Картинка (meteor impact)
        $photo = base_url('uploads/telegram/due_to_meteor_impact.png');

        // Уведомление
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке MeteorShowerHandler: " . $e->getMessage());
        }
    }
}
