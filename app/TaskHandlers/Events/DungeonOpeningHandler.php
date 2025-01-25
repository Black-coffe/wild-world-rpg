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

class DungeonOpeningHandler extends Controller
{
    protected $characterModel;
    protected $characterTaskModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $telegramUserModel;
    protected $eventModel;
    protected $activeEventModel;
    protected $biomeModel;
    protected $taskModel;
    private   $telegram;

    public function __construct()
    {
        // Инициализация моделей
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->eventModel            = new EventModel();
        $this->activeEventModel      = new ActiveEventModel();
        $this->biomeModel            = new BiomeModel();
        $this->taskModel             = new TaskModel();

        // Инициализация Telegram Bot
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
        Request::initialize($this->telegram);
    }

    /**
     * Основной метод обработки события.
     * Вызывается периодически (cron) или при логике игрового цикла.
     */
    public function process()
    {
        // 1. Найти в таблице events запись для "RevealingHiddenCameras"
        $eventInfo = $this->eventModel->where('name_english', 'RevealingHiddenCameras')->first();
        if (!$eventInfo) {
            return;
        }

        // 2. Проверить, активно ли оно в active_events (status = 'active')
        $active = $this->activeEventModel
            ->where('event_id', $eventInfo['event_id'])
            ->where('status', 'active')
            ->first();

        if (!$active) {
            // Событие не активно, выходим
            return;
        }

        // 3. Берём все биомы cave (пещеры)
        $caveBiomes = $this->biomeModel->where('biome_type', 'cave')->findAll();
        $caveBiomeIds = array_column($caveBiomes, 'id');

        if (empty($caveBiomeIds)) {
            return;
        }

        // 4. Ищем всех персонажей, кто сейчас в этих пещерах
        $charactersInCaves = $this->characterModel->whereIn('biome_id', $caveBiomeIds)->findAll();
        if (!$charactersInCaves) {
            return;
        }

        // 5. Ищем task_id для сбора ресурсов (Gather)
        $gatherTask = $this->taskModel->where('name', 'Gather')->first();
        if (!$gatherTask) {
            return;
        }
        $gatherTaskId = $gatherTask['id'];

        // 6. Проходим по каждому персонажу и проверяем, есть ли у него "Gather" в статусе in_work
        foreach ($charactersInCaves as $character) {
            $isGathering = $this->characterTaskModel->where([
                'character_id' => $character['id'],
                'task_id'      => $gatherTaskId,
                'status'       => 'in_work',
            ])->first();

            if ($isGathering) {
                // 7. Обрабатываем доп. логику события
                $this->handleResourceAllocation($character);
            }
        }
    }

    /**
     * Выдача редкого ресурса с 5% шансом и количеством 1..3.
     */
    private function handleResourceAllocation($character)
    {
        // Находим список ресурсов для "RevealingHiddenCameras" (в текущем biome_id, rarity=1, keyword='RevealingHiddenCameras')
        $biomeResources = $this->resourceModel
            ->where('biome_id', $character['biome_id'])
            ->where('rarity', 1)
            ->where('keyword', 'RevealingHiddenCameras')
            ->findAll();

        // Если нечего давать — выходим
        if (empty($biomeResources)) {
            return;
        }

        // Шанс 5% получить редкий ресурс
        $roll = mt_rand(1, 100);
        if ($roll > 5) {
            // 95% случаёв игрок ничего не получает
            return;
        }

        // Если 5% выпали, выбираем случайный ресурс из списка
        $resource = $biomeResources[array_rand($biomeResources)];

        // Количество — случайно 1..3
        $amount = mt_rand(1, 3);

        // Добавляем в character_resources
        $existingResource = $this->characterResourceModel->where([
            'id_characters' => $character['id'],
            'id_resources'  => $resource['id']
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] + $amount;
            $this->characterResourceModel->update($existingResource['id'], ['quantity' => $newQuantity]);
        } else {
            $this->characterResourceModel->insert([
                'id_characters' => $character['id'],
                'id_resources'  => $resource['id'],
                'quantity'      => $amount,
            ]);
        }

        // Отправка уведомления в Telegram
        $this->notifyCharacter($character, $resource['name'], $amount);
    }

    /**
     * Отправляем сообщение игроку о полученном редком ресурсе.
     */
    private function notifyCharacter($character, $resourceName, $amount)
    {
        $telegramUser = $this->telegramUserModel->find($character['telegram_user_id']);
        if (!$telegramUser) {
            return;
        }

        $chatId = $telegramUser['telegram_id'] ?? null;
        if (!$chatId) {
            return;
        }

        // Текст сообщения
        $message = "🎉 *В глубинах пещеры что-то блеснуло...*\n\n"
            . "Кажется, тебе посчастливилось наткнуться на *{$amount}* шт. "
            . "редкого ресурса _{$resourceName}_!\n\n"
            . "Это стало возможным благодаря событию "
            . "*«Открытие скрытых подземелий»* (RevealingHiddenCameras).";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ]
            ]
        ];

        $photo = base_url('uploads/telegram/dragon_heart.png');

        try {
            Request::sendPhoto([
                'chat_id'    => $chatId,
                'photo'      => Request::encodeFile($photo),
                'caption'    => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (TelegramException $e) {
            log_message('error', "Ошибка при отправке фото: " . $e->getMessage());
            // На случай ошибок можно отправить просто текст
            Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => strip_tags($message),
                'parse_mode' => 'Markdown'
            ]);
        }
    }
}
