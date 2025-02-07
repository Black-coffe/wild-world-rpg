<?php

namespace App\TaskHandlers;

use App\Models\{
    CharacterDataModel,
    CharacterMessageStatusModel,
    CharacterResourceModel,
    CharacterModel,
    BiomeModel,
    MapModel,
    ResourceModel,
    TelegramUserModel
};
use App\Libraries\TelegramMessages;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * Класс CharacterDataHandler
 *
 * Предназначен для обработки «дополнительных данных» (CharacterData) и отправки сообщений
 * игрокам (через Telegram), если их уровень или другие условия достигли нужных порогов.
 *
 * Главные шаги:
 * 1) В методе process() получаем всех персонажей из CharacterModel.
 * 2) Для каждого персонажа вызываем characterLevelProcessing(), где:
 *    - Сравниваем текущий уровень персонажа (character['level']) с записями в CharacterData,
 *      имеющими поле character_level <= текущего уровня.
 *    - Проверяем, отправляли ли уже игроку сообщение об этом уровне (справка в character_message_status).
 *    - Если не отправляли — создаём запись в character_message_status со статусом 'done' и
 *      отправляем Telegram-сообщение игроку, используя message_text из character_data.
 *
 * Таким образом, данный хендлер позволяет выдавать «уровневые» сообщения, подсказки или награды,
 * когда персонаж достигает определённых значений level.
 */
class CharacterDataHandler
{
    /**
     * @var CharacterDataModel $characterDataModel
     * Модель таблицы `character_data`:
     * хранит «расширенные данные» или «пороговые» записи (например, message_text, привязанные к определённым уровням).
     */
    protected $characterDataModel;

    /**
     * @var CharacterMessageStatusModel $characterMessageStatusModel
     * Модель таблицы `character_message_status`:
     * хранит запись статуса (done/...) о том,
     * было ли уже выслано сообщение (или выполнено действие) для конкретного character_data_id.
     */
    protected $characterMessageStatusModel;

    /**
     * @var CharacterModel $characterModel
     * Модель таблицы characters (основная информация о персонаже).
     */
    protected $characterModel;

    /**
     * @var BiomeModel $biomeModel
     * Модель таблицы biomes. (В данном классе не используется напрямую,
     * но может применяться при расширении логики).
     */
    protected $biomeModel;

    /**
     * @var MapModel $mapModel
     * Модель таблицы map. (Также не используется здесь напрямую,
     * но загружается для потенциальной логики, связанной с картой).
     */
    protected $mapModel;

    /**
     * @var ResourceModel $resourceModel
     * Модель таблицы resources. (Загружается, если при повышении уровня нужно,
     * к примеру, выдавать ресурсы, однако в текущем коде не применяется).
     */
    protected $resourceModel;

    /**
     * @var CharacterResourceModel $characterResourceModel
     * Модель таблицы character_resources (ресурсы персонажа).
     * В текущем коде не используется, но может пригодиться при расширении.
     */
    protected $characterResourceModel;

    /**
     * @var TelegramUserModel $telegramUserModel
     * Модель таблицы telegram_users, нужна для определения `telegram_id` (чата) пользователя,
     * чтобы затем отправлять ему сообщение.
     */
    protected $telegramUserModel;

    /**
     * @var TelegramMessages $telegramMessages
     * Класс-«библиотека», хранящий заранее подготовленные тексты сообщений
     * (например, «character_level_2», «character_level_5» и т.п.).
     */
    protected $telegramMessages;

    /**
     * @var Telegram $telegram
     * Экземпляр Telegram SDK (longman/telegram-bot),
     * с помощью которого отправляются сообщения/фото.
     */
    private $telegram;

    /**
     * Конструктор: инициализирует все необходимые модели и библиотеку сообщений.
     * Также пытается создать объект Telegram, используя ключ из окружения.
     */
    public function __construct()
    {
        // Библиотека сообщений (хранит тексты)
        $this->telegramMessages            = new TelegramMessages();

        // Модели
        $this->characterDataModel          = new CharacterDataModel();
        $this->characterMessageStatusModel = new CharacterMessageStatusModel();
        $this->characterModel              = new CharacterModel();
        $this->mapModel                    = new MapModel();
        $this->biomeModel                  = new BiomeModel();
        $this->resourceModel               = new ResourceModel();
        $this->characterResourceModel      = new CharacterResourceModel();
        $this->telegramUserModel           = new TelegramUserModel();

        // Инициализация Telegram
        $API_KEY      = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Если нужно, регистрируем команды
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    /**
     * Основной метод обработчика (handler).
     * 1) Получаем список всех персонажей (из таблицы characters).
     * 2) Для каждого персонажа вызываем characterLevelProcessing($character).
     *
     * Основная цель — проверить, достиг ли персонаж определённого уровня,
     * и, если да, отправить ему соответствующее сообщение (один раз, при первом достижении).
     */
    public function process()
    {
        // 1) Получаем всех персонажей
        $allCharacters = $this->characterModel->findAll();

        // 2) Для каждого запускаем логику
        foreach ($allCharacters as $character)
        {
            $this->characterLevelProcessing($character);
        }
    }

    /**
     * Метод для отправки сообщения пользователю в Telegram с кнопками и картинкой.
     *
     * @param int $telegramId  — chat_id пользователя (или callback_query_id),
     * @param string $text     — текст (caption) сообщения
     * @param mixed $keyboard  — клавиатура для Telegram (по умолчанию подставляется базовая)
     * @param mixed $imagePath — путь к картинке (по умолчанию подставляется базовый)
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    public function sendMessageToTelegram($telegramId, $text, $keyboard, $imagePath)
    {
        // Если клавиатура не задана, используем дефолт
        if ($keyboard === null || $keyboard === 0 || $keyboard === '') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                        ['text' => '🎉 События', 'callback_data' => 'events']
                    ]
                ]
            ];
        }

        // Если путь к картинке не задан, берём дефолтную
        if ($imagePath === null || $imagePath === 0 || $imagePath === '') {
            $imagePath = base_url('uploads/telegram/send_message_telegram_info_from_character_data.png');
        } else {
            $imagePath = base_url($imagePath);
        }

        // Посылаем ответ на «колбэк» (вдруг юзер ждал)
        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);

        // Отправляем фото в чат
        return Request::sendPhoto([
            'chat_id' => $telegramId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Логика обработки «уровня персонажа».
     * 1) Смотрим на текущий level персонажа (из поля 'level').
     * 2) В таблице character_data ищем все записи, у которых character_level <= этот уровень.
     * 3) Для каждой такой записи проверяем в character_message_status,
     *    отправляли ли мы уже соответствующее сообщение (статус='done').
     * 4) Если не отправляли, вставляем 'done' и отправляем сообщение в Telegram (с текстом из character_data.message_text).
     *
     * Таким образом, если у character_data имеется строка «character_level=5»,
     * а персонаж впервые достиг 5 уровня, то при проходе логики один раз высылается сообщение,
     * и потом повторно не отправится.
     *
     * @param array $character — строка из таблицы characters
     */
    private function characterLevelProcessing($character)
    {
        // Текущий уровень из characters
        $levelCharacter = $character['level'];

        // 2) Найдём все записи в character_data,
        //    где character_level <= $levelCharacter
        $characterDataWithLevel = $this->characterDataModel
            ->where('character_level <=', $levelCharacter)
            ->findAll();

        // Перебираем каждую запись
        foreach ($characterDataWithLevel as $row)
        {
            // 3) Проверяем character_message_status:
            // ищем запись со status='done'?
            $characterMessageStatus = $this->characterMessageStatusModel
                ->where('character_id', $character['id'])
                ->where('character_data_id', $row['id'])
                ->where('status', 'done')
                ->first();

            if (!$characterMessageStatus) {
                // Если записи нет, значит ещё не отправляли сообщение.
                // 3a) Создаём запись в character_message_status со статусом 'done'
                $this->characterMessageStatusModel->insert([
                    'character_id'      => $character['id'],
                    'character_data_id' => $row['id'],
                    'status'            => 'done'
                ]);

                // 3b) Готовим Telegram-сообщение
                // Смотрим поле 'message_text' в character_data,
                // а реальный текст берём из нашей библиотеки $telegramMessages->getMessageByKey(...)
                $messageKey = $row['message_text'];
                $msgText    = $this->telegramMessages->getMessageByKey($messageKey);

                // 3c) Узнаём chat_id пользователя (telegram_id)
                $telegramId = $this->telegramUserModel
                    ->where('id', $character['telegram_user_id'])
                    ->first()['telegram_id'];

                // 3d) Отправляем
                $this->sendMessageToTelegram($telegramId, $msgText, 0, 0);
            }
        }
    }
}
