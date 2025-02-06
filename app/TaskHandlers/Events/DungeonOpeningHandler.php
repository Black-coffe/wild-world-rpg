<?php

namespace App\TaskHandlers\Events;

use CodeIgniter\Controller;
use App\Models\{
    CharacterModel,
    TaskModel,
    EventModel,
    BiomeModel,
    ResourceModel,
    CharacterTaskModel,
    CharacterResourceModel,
    TelegramUserModel,
    ActiveEventModel
};
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс DungeonOpeningHandler
 *
 * Обрабатывает событие "Открытие скрытых подземелий" (RevealingHiddenCameras):
 * - Считается «локальным» событием (event_type='local'), которое активируется при определённом biоме 'cave'.
 * - Даёт шанс (5%) найти редкий ресурс при выполнении задачи "Gather" (сбор ресурсов)
 *   для персонажа, находящегося в пещере.
 *
 * Уровень влияния на персонажей:
 * - В данном примере мы не меняем напрямую health/опыт/золото,
 *   а лишь выдаём дополнительные редкие ресурсы (quantity 1..3).
 * - Если в будущем потребуется модифицировать здоровье (effect_type='damage' или 'heal'),
 *   можно добавить соответствующую логику (см. пример, как можно работать с полем health).
 */
class DungeonOpeningHandler extends Controller
{
    /** @var CharacterModel Модель персонажей */
    protected $characterModel;
    /** @var CharacterTaskModel Модель задач персонажей */
    protected $characterTaskModel;
    /** @var ResourceModel Модель ресурсов */
    protected $resourceModel;
    /** @var CharacterResourceModel Модель связей "персонаж - ресурс" */
    protected $characterResourceModel;
    /** @var TelegramUserModel Модель Telegram-пользователей */
    protected $telegramUserModel;
    /** @var EventModel Модель событий */
    protected $eventModel;
    /** @var ActiveEventModel Модель активных событий */
    protected $activeEventModel;
    /** @var BiomeModel Модель биомов (для определения pещер) */
    protected $biomeModel;
    /** @var TaskModel Модель задач (ищем "Gather") */
    protected $taskModel;

    /** @var Telegram Экземпляр Telegram-бота */
    private $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->eventModel             = new EventModel();
        $this->activeEventModel       = new ActiveEventModel();
        $this->biomeModel             = new BiomeModel();
        $this->taskModel              = new TaskModel();

        // Инициализация Telegram Bot
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод обработки события:
     * 1) Ищем событие "RevealingHiddenCameras" в таблице events.
     * 2) Проверяем, активно ли оно в active_events (status = 'active').
     * 3) Находим персонажей, находящихся в пещерах (biome_type='cave').
     * 4) Если у персонажа сейчас идёт задача "Gather" -> выдаём шанс получить редкие ресурсы.
     */
    public function process()
    {
        // 1. Находим событие по name_english="RevealingHiddenCameras"
        $eventInfo = $this->eventModel->where('name_english', 'RevealingHiddenCameras')->first();
        if (!$eventInfo) {
            // Если в таблице events нет такой записи, завершить
            return;
        }

        // 2. Проверяем, активно ли данное событие (status = 'active')
        $active = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$active) {
            // Событие не активно, завершаем
            return;
        }

        // 3. Ищем все биомы, где biome_type='cave'
        //    (В нашем случае пещеры, где потенциально может открыться "скрытый проход")
        $caveBiomes = $this->biomeModel
            ->where('biome_type', 'cave')
            ->findAll();

        // Собираем ID всех таких биомов
        $caveBiomeIds = array_column($caveBiomes, 'id');
        if (empty($caveBiomeIds)) {
            // Если нет ни одного биома cave — завершаем
            return;
        }

        // 4. Получаем всех персонажей, чьи biome_id входит в список пещер
        $charactersInCaves = $this->characterModel
            ->whereIn('biome_id', $caveBiomeIds)
            ->findAll();
        if (!$charactersInCaves) {
            // Нет персонажей в пещерах — завершаем
            return;
        }

        // 5. Ищем задачу Gather (сбор ресурсов),
        //    чтобы определить, кто активно занимается сбором
        $gatherTask = $this->taskModel->where('name', 'Gather')->first();
        if (!$gatherTask) {
            // Нет такой задачи, значит никто не может собирать ресурсы
            return;
        }
        $gatherTaskId = $gatherTask['id'];

        // 6. Для каждого персонажа в пещерах проверяем, запущена ли у него задача "Gather"
        foreach ($charactersInCaves as $character) {
            $isGathering = $this->characterTaskModel->where([
                'character_id' => $character['id'],
                'task_id'      => $gatherTaskId,
                'status'       => 'in_work',
            ])->first();

            if ($isGathering) {
                // 7. Если персонаж в процессе Gather, даём ему шанс получить редкий ресурс
                $this->handleResourceAllocation($character);
            }
        }
    }

    /**
     * Выдача редкого ресурса (1..3 шт.) с вероятностью 5%.
     * Логика события "Открытие скрытых подземелий":
     * - Берём только те ресурсы, у которых rarity=1 (редкие) и keyword="RevealingHiddenCameras"
     * - biome_id должно совпадать с biome_id персонажа.
     *
     * @param array $character Ассоциативный массив данных о персонаже
     */
    private function handleResourceAllocation(array $character)
    {
        // 1) Находим все ресурсы в данном биоме, редкие (rarity=1),
        //    и с keyword="RevealingHiddenCameras"
        $biomeResources = $this->resourceModel
            ->where('biome_id', $character['biome_id'])
            ->where('rarity', 1)
            ->where('keyword', 'RevealingHiddenCameras')
            ->findAll();

        // Если таких ресурсов нет — нечего выдавать
        if (empty($biomeResources)) {
            return;
        }

        // 2) С вероятностью 5% выдаём ресурс
        //    (Псевдокод: roll = случайное число 1..100, если <=5 => успех)
        $roll = mt_rand(1, 100);
        if ($roll > 10) {
            // 90% случаев - ничего не даём
            return;
        }

        // 3) Иначе (5%) — случайно выбираем 1 ресурс из подходящих
        $resource = $biomeResources[array_rand($biomeResources)];

        // 4) Количество — случайно от 1 до 3
        $amount = mt_rand(1, 3);

        // 5) Увеличиваем запись в character_resources
        //    (или создаём новую, если её нет)
        $existingResource = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->where('id_resources',  $resource['id'])
            ->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] + $amount;
            $this->characterResourceModel->update($existingResource['id'], [
                'quantity' => $newQuantity
            ]);
        } else {
            $this->characterResourceModel->insert([
                'id_characters' => $character['id'],
                'id_resources'  => $resource['id'],
                'quantity'      => $amount
            ]);
        }

        // 6) Уведомляем игрока
        $this->notifyCharacter($character, $resource['name'], $amount);
    }

    /**
     * Уведомляем игрока о том, что он получил редкий ресурс.
     *
     * @param array $character Данные персонажа
     * @param string $resourceName Название ресурса (rus)
     * @param int $amount Сколько единиц ресурса выдали
     */
    private function notifyCharacter(array $character, string $resourceName, int $amount)
    {
        // 1) Ищем запись TelegramUser
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            // Если нет TelegramUser, не можем отправить сообщение
            return;
        }

        $chatId = $telegramUser['telegram_id'] ?? null;
        if (!$chatId) {
            // Если нет телеграм-чат-айди, тоже невозможно уведомить
            return;
        }

        // 2) Формируем сообщение
        $message = "🎉 *В глубинах пещеры что-то блеснуло...*\n\n"
            . "Кажется, тебе посчастливилось наткнуться на *{$amount}* шт. "
            . "редкого ресурса _{$resourceName}_!\n\n"
            . "Это стало возможным благодаря событию *«Открытие скрытых подземелий»* _(RevealingHiddenCameras)_.\n\n"
            . "_Совет_: продолжай обыскивать пещеры, пока событие активно!";

        // Кнопки для удобства
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',        'callback_data' => 'events']
                ]
            ]
        ];

        // 3) Картинка (условно "dragon_heart.png") или любая подходящая
        $photo = base_url('uploads/telegram/dragon_heart.png');

        // 4) Отправка фото + caption
        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            // В случае ошибки — логируем и пытаемся отправить просто текст
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => strip_tags($message),
                'parse_mode' => 'Markdown',
            ]);
        }
    }
}
