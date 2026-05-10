<?php

namespace App\Controllers\Telegram\Commands\Actions\Sell;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

class SellCraftAction extends BaseAction
{
    protected $characterModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel       = new CharacterModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel    = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Проверка наличия хотя бы одного скрафченного ресурса у персонажа
        $craftedItemsLog = $this->craftedItemsLogModel
            ->where('character_id', $character['id'])
            ->findAll();

        if (empty($craftedItemsLog)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет скрафченных ресурсов для продажи.',
            ]);
        }

        // Получение всех типов крафтовых предметов у персонажа
        $craftedItems = [];
        foreach ($craftedItemsLog as $log) {
            $craftedItem = $this->craftedItemsModel->find($log['crafted_item_id']);
            if ($craftedItem) {
                $type = $craftedItem['type'] ?? 'unknown';
                if (!isset($craftedItems[$type])) {
                    $craftedItems[$type] = [
                        'type' => $type,
                        'type_rus' => $this->translateType($type),
                    ];
                }
            }
        }

        // Формирование сообщения и кнопок
        $text = "*Привет друг!* \n"
            . "Ты зашел ко мне в лавку и решил продать немного своего добра, "
            . "которое ты так усердно крафтил.\n\n"
            . "Ну что ж, показывай, что там у тебя есть?\n";

        $keyboardButtons = [];

        foreach ($craftedItems as $type => $item) {
            $keyboardButtons[] = [
                'text' => $item['type_rus'],
                'callback_data' => 'sellCraftList_' . $type
            ];
        }

        // Добавим кнопки персонаж / инвентарь
        $keyboardButtons[] = ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'];
        $keyboardButtons[] = ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'];

        $keyboard = array_chunk($keyboardButtons, 2);

        $imagePath = base_url('uploads/telegram/craft/vendor_kiosk_in_the_game_world.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Переводим type -> человекопонятное название с эмоджи.
     */
    private function translateType($type)
    {
        $translations = [
            'workbench' => '🔬 Верстаки',
            'component' => '📐 Компоненты',
            'transport' => '🛴 Транспорт',
            'tool'      => '🛠️ Инструменты',
            'drug'      => '💊 Лекарства',
            'robots'    => '🤖 Роботы',
            'teleport'  => '🌀 Телепорт-маяки',   // <-- Добавлена новая группа
        ];

        return $translations[$type] ?? ("❓" . $type);
    }
}
