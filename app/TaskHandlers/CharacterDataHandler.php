<?php

namespace App\TaskHandlers;

use App\Models\CharacterDataModel;
use App\Models\CharacterMessageStatusModel;
use App\Models\CharacterResourceModel;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use App\Models\CharacterModel;
use App\Models\BiomeModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\TelegramUserModel;
use App\Libraries\TelegramMessages;

class CharacterDataHandler
{
    protected $characterDataModel;
    protected $characterMessageStatusModel;
    protected $characterModel;
    protected $biomeModel;
    protected $mapModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $telegramUserModel;
    protected $telegramMessages;
    private $telegram;

    public function __construct()
    {
        $this->telegramMessages = new TelegramMessages();
        $this->characterDataModel = new CharacterDataModel();
        $this->characterMessageStatusModel = new CharacterMessageStatusModel();
        $this->characterModel = new CharacterModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->resourceModel = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->telegramUserModel = new TelegramUserModel();
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function process()
    {
        // Получаем список всех игроков (персонажей)
        $allCharacters = $this->characterModel->findAll();

        foreach ($allCharacters as $character)
        {
            $this->characterLevelProcessing($character);
        }


    }

    public function sendMessageToTelegram($telegramId, $text, $keyboard, $imagePath)
    {
        if($keyboard === null or $keyboard === 0 or $keyboard === ''){
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

        if($imagePath === null or $imagePath === 0 or $imagePath === ''){
            $imagePath = base_url('uploads/telegram/send_message_telegram_info_from_character_data.png');
        }else{
            $imagePath = base_url($imagePath);
        }

        Request::answerCallbackQuery(['callback_query_id' => $telegramId]);
        return Request::sendPhoto([
            'chat_id' => $telegramId,
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
            'disable_web_page_preview' => true,
        ]);
    }

    // Обработка уровней персонажа в таблице `character_data` поле: character_level
    private function characterLevelProcessing($character)
    {
        $levelCharacter = $character['level'];
        $characterDataWithLevel = $this->characterDataModel->where('character_level<=', $levelCharacter)->findAll();

        foreach ($characterDataWithLevel as $row)
        {
            $characterMessageStatus = $this->characterMessageStatusModel
                ->where('character_id', $character['id'])
                ->where('character_data_id', $row['id'])
                ->where('status', 'done')
                ->first();

            if (!$characterMessageStatus) {
                // Если такой записи нет, делаем запись в таблице `character_message_status` и ставим статус: 'done'
                $this->characterMessageStatusModel->insert([
                    'character_id' => $character['id'],
                    'character_data_id' => $row['id'],
                    'status' => 'done'
                ]);

                // Получаем telegram_id пользователя
                $telegramId = $this->telegramUserModel->where('id', $character['telegram_user_id'])->first()['telegram_id'];

                // Вызываем метод отправки сообщения в телеграм
                $this->sendMessageToTelegram($telegramId, $this->telegramMessages->getMessageByKey($row["message_text"]), 0, 0);
            }
        }

    }
}
