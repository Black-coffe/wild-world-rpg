<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class AllRobotsHandler extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
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

        $characterId = $character['id'];

        // Получаем все ID роботов из таблицы crafted_items, где type = "robots"
        $robotIds = $this->craftedItemsModel->where('type', 'robots')->findAll();
        $robotIds = array_column($robotIds, 'id');

        if (empty($robotIds)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет доступных роботов.',
            ]);
        }

        // Получаем роботов, которые есть у персонажа
        $robots = $this->craftedItemsLogModel->whereIn('crafted_item_id', $robotIds)
            ->where('character_id', $characterId)
            ->findAll();

        if (empty($robots)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет доступных роботов.',
            ]);
        }

        // Сбор информации о роботах
        $robotInfo = [];
        foreach ($robots as $robot) {
            $robotDetails = $this->craftedItemsModel->find($robot['crafted_item_id']);
            if (isset($robotDetails['name_rus'])) {
                if (!isset($robotInfo[$robotDetails['name_rus']])) {
                    $robotInfo[$robotDetails['name_rus']] = [
                        'quantity' => 0,
                        'durability_count' => 0,
                    ];
                }
                $robotInfo[$robotDetails['name_rus']]['quantity'] += $robot['quantity'];
                $robotInfo[$robotDetails['name_rus']]['durability_count'] += $robot['durability_count'] * $robot['quantity'];
            }
        }

        if (empty($robotInfo)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'У вас нет доступных роботов.',
            ]);
        }

        $text = "*У тебя на базе доступны такие роботы:*\n\n";
        foreach ($robotInfo as $name => $info) {
            $text .= sprintf("- %s / %d шт. / %d использований\n", $name, $info['quantity'], $info['durability_count']);
        }
        $text .= "\n_Выбери внизу робота для его активации_";

        // Создание кнопок для каждого робота
        $keyboardButtons = [];
        foreach ($robotInfo as $name => $info) {
            $craftedItemId = $this->craftedItemsModel->where('name_rus', $name)->first()['id'];
            $keyboardButtons[] = [
                'text' => $name,
                'callback_data' => 'activateRobot_' . $craftedItemId
            ];
        }

        $imagePath = base_url('uploads/telegram/craft/standard/all_robots.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
