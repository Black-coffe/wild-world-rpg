<?php

namespace App\Controllers\Telegram\Commands\Actions\StartGame;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class WithoutTrainingStartAction extends BaseAction
{
    protected $characterModel;
    protected $exploredCellsModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $characterResourceModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Формируем сообщение
        $text = "🎉 *Твой выбор — твой путь, путешественник!* 🌟\n\n"
            . "🚀 Ты решил отказаться от обучения и сразу погрузиться в приключения — и это *восхитительно!* Закатываем рукава и вперёд, к эффективному выживанию и завоеванию *Wild World!*\n\n"
            . "🔍 *Но помни*, вверху в переписке остаётся кнопка '*💡 Пройти обучение*'. Она всегда доступна, и ты можешь в любой момент вернуться к обучению, чтобы узнать больше о мире и его тонкостях. Даже если ты уже профессионал сотней часов в игре! 🕒\n\n"
            . "🌟 _Я благодарен тебе за выбор нашей игры и уверен, что ты проявишь себя как настоящий герой и изощрённый стратег! Вперёд, к новым вершинам и победам!_\n\n"
            . "👋 До скорой встречи в *Wild World*! Жду новостей о твоих захватывающих приключениях! 🌌\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/picture_of_the_playable_character.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Ответ в телеграм
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
