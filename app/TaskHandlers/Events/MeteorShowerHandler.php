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

// Подключаем сервис для проверки, находится ли игрок на базе
use App\Services\Player\PlayerStateService;

/**
 * Класс MeteorShowerHandler
 *
 * Обрабатывает событие "MeteorRain" (Метеоритный дождь):
 * 1) Проверяет, активно ли событие (active_events, end_time >= now).
 * 2) Случайно выбирает 1000 игровых ячеек (cell_number).
 * 3) По этим ячейкам ищет персонажей.
 * 4) Для каждого персонажа 50/50: урон по ресурсам ИЛИ урон по персонажу.
 * 5) Если персонаж "на базе", урон уменьшается на 75% (т.е. остаётся 25%).
 * 6) Уведомляем игрока.
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

    /** @var PlayerStateService */
    protected $playerStateService;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->biomeModel             = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->telegramUserModel      = new TelegramUserModel();

        // Инициализация PlayerStateService
        $this->playerStateService = new PlayerStateService();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Главный метод process():
     * 1) Находим "MeteorRain" в eventModel.
     * 2) Проверяем, активно ли событие (status='active', end_time >= now).
     * 3) Случайно выбираем N ячеек (cell_number).
     * 4) Ищем персонажей, находящихся в этих ячейках.
     * 5) Для каждого персонажа: 50% урон ресурсам / 50% урон персонажу,
     *    с учётом, что на базе урон снижается на 75%.
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

        // 2) Проверяем, активно ли событие
        $isActive = $this->activeEventModel
            ->where('event_id', $meteorEvent['event_id'])
            ->where('status', 'active')
            ->where('end_time >=', date('Y-m-d H:i:s'))
            ->first();

        if (!$isActive) {
            return; // событие неактивно — выходим
        }

        // 3) Случайно выбираем N ячеек (здесь 1000)
        $selectedCells = $this->selectRandomCells(1000);

        // 4) Ищем персонажей, у которых cell_number в $selectedCells
        $affectedCharacters = $this->characterModel
            ->whereIn('cell_number', $selectedCells)
            ->findAll();

        // 5) Для каждого персонажа 50/50: урон ресурсам / урон по персонажу
        foreach ($affectedCharacters as $character) {
            $roll = mt_rand(0, 1);
            if ($roll === 0) {
                $this->applyResourceDamage($character, $meteorEvent);
            } else {
                $this->applyPersonalDamage($character, $meteorEvent);
            }
        }
    }

    /**
     * Формирует массив случайных cell_number.
     * По логике, cell_number может быть от 1 до 1,000,000 (пример).
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
     * Уменьшаем ресурсы персонажа на effect_value%,
     * но если персонаж на базе — уменьшаем урон на 75%.
     */
    protected function applyResourceDamage(array $character, array $eventInfo)
    {
        $effectValue = $eventInfo['effect_value'];

        // Если персонаж на базе, урон снижается на 75% => умножаем effectValue на 0.25
        if ($this->playerStateService->isCharacterOnBase($character['id'])) {
            $effectValue = $effectValue * 0.25;
        }

        $resources = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->findAll();

        foreach ($resources as $resource) {
            $oldQty = (int)$resource['quantity'];
            if ($oldQty <= 1) {
                // уже 1 — не снижаем далее
                continue;
            }

            // damageQty = oldQty * (effectValue/100)
            $damageQty = (int)ceil($oldQty * ($effectValue / 100.0));
            $newQty    = $oldQty - $damageQty;
            if ($newQty < 1) {
                $newQty = 1;
            }

            // Обновляем
            $this->characterResourceModel->update($resource['id'], [
                'quantity' => $newQty
            ]);
        }

        $this->notifyCharacter($character, "resource damage", $effectValue);
    }

    /**
     * Уменьшаем health/tired на effect_value% (минимум 0.01),
     * если персонаж на базе — уменьшаем урон на 75%.
     */
    protected function applyPersonalDamage(array $character, array $eventInfo)
    {
        $effectValue = $eventInfo['effect_value'];

        // Проверяем, на базе ли игрок
        if ($this->playerStateService->isCharacterOnBase($character['id'])) {
            $effectValue = $effectValue * 0.25;
        }

        $oldHealth = $character['health'];
        $oldTired  = $character['tired'];

        $damageHealth = $oldHealth * ($effectValue / 100.0);
        $damageTired  = $oldTired  * ($effectValue / 100.0);

        $newHealth    = max(0.01, $oldHealth - $damageHealth);
        $newTired     = max(0.01, $oldTired  - $damageTired);

        // Обновляем персонажа
        $this->characterModel->update($character['id'], [
            'health' => $newHealth,
            'tired'  => $newTired
        ]);

        $this->notifyCharacter($character, "personal damage", $effectValue);
    }

    /**
     * Уведомляем игрока в Telegram о последствиях метеоритного дождя.
     */
    protected function notifyCharacter(array $character, string $type, float $effectValue)
    {
        // Ищем телеграм-пользователя
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

        $message = "⚠️ *Метеоритный дождь* повлиял на вашего персонажа!\n\n";
        if ($type === "resource damage") {
            $message .= "• Ресурсы сокращены на ~*" . round($effectValue,2) . "%*.\n";
        } else {
            $message .= "• Здоровье и выносливость уменьшились на ~*" . round($effectValue,2) . "%*.\n";
        }

        $message .= "\n_Если вы находитесь на базе, урон снижен на 75%!_\n";
        $message .= "Продолжайте действовать осторожно и следите за длительностью события.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events']
                ]
            ]
        ];

        $photo = base_url('uploads/telegram/due_to_meteor_impact.png');

        // Отправляем уведомление (фото + подпись)
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
