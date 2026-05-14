<?php

namespace App\TaskHandlers;

use App\Attributes\HandlerKey;
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

/**
 * Класс CharacterDataHandler
 *
 * Предназначен для обработки «дополнительных данных» (CharacterData) и отправки сообщений
 * игрокам (через Telegram), если их уровень или другие условия достигли нужных порогов.
 *
 * Главные шаги:
 * 1) В методе handle() получаем всех персонажей из CharacterModel.
 * 2) Для каждого персонажа вызываем characterLevelProcessing(), где:
 *    - Сравниваем текущий уровень персонажа (character['level']) с записями в CharacterData,
 *      имеющими поле character_level <= текущего уровня.
 *    - Проверяем, отправляли ли уже игроку сообщение об этом уровне (справка в character_message_status).
 *    - Если не отправляли — создаём запись в character_message_status со статусом 'done' и
 *      отправляем Telegram-сообщение игроку, используя message_text из character_data.
 *
 * v0.51.42 (F2.9 batch-7 final): extends BaseTaskHandler. Drop manual Telegram init.
 * process() → handle(array $task = []): void. Drop **broken** `Request::
 * answerCallbackQuery(['callback_query_id' => $telegramId])` (chat_id passed
 * as callback_query_id — fires daily silently). Request::sendPhoto → safeSendPhoto.
 */
#[HandlerKey(
    key: 'character_data_refresh',
    displayName: 'Лор-сообщения по уровням',
    description: 'Recurring (Tasks.php every minute): отправляет лор-сообщения из character_data при достижении игроком соответствующего уровня.',
)]
class CharacterDataHandler extends BaseTaskHandler
{
    /** @var CharacterDataModel */
    protected $characterDataModel;

    /** @var CharacterMessageStatusModel */
    protected $characterMessageStatusModel;

    /** @var CharacterModel */
    protected $characterModel;

    /** @var BiomeModel */
    protected $biomeModel;

    /** @var MapModel */
    protected $mapModel;

    /** @var ResourceModel */
    protected $resourceModel;

    /** @var CharacterResourceModel */
    protected $characterResourceModel;

    /** @var TelegramUserModel */
    protected $telegramUserModel;

    /** @var TelegramMessages */
    protected $telegramMessages;

    public function __construct()
    {
        $this->telegramMessages            = new TelegramMessages();
        $this->characterDataModel          = new CharacterDataModel();
        $this->characterMessageStatusModel = new CharacterMessageStatusModel();
        $this->characterModel              = new CharacterModel();
        $this->mapModel                    = new MapModel();
        $this->biomeModel                  = new BiomeModel();
        $this->resourceModel               = new ResourceModel();
        $this->characterResourceModel      = new CharacterResourceModel();
        $this->telegramUserModel           = new TelegramUserModel();
    }

    /**
     * Основной метод обработчика.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature.
     */
    public function handle(array $task = []): void
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
     * @param int|string $telegramId  — chat_id пользователя
     * @param string $text     — текст (caption) сообщения
     * @param mixed $keyboard  — клавиатура для Telegram (по умолчанию подставляется базовая)
     * @param mixed $imagePath — путь к картинке (по умолчанию подставляется базовый)
     */
    public function sendMessageToTelegram($telegramId, string $text, $keyboard, $imagePath): void
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

        $this->safeSendPhoto(
            $telegramId,
            $imagePath,
            $text,
            [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard),
                'disable_web_page_preview' => true,
            ]
        );
    }

    /**
     * Логика обработки «уровня персонажа».
     *
     * @param array|\App\Entities\CharacterEntity $character — строка из таблицы characters
     */
    private function characterLevelProcessing($character): void
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
