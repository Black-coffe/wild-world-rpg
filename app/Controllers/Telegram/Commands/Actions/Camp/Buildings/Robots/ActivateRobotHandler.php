<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ActivateRobotHandler extends BaseAction
{
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        $callbackData = $this->callbackQuery->getData();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Получаем ID робота из callback_data
        $robotId = str_replace('activateRobot_', '', $callbackData);

        // Получаем информацию о роботе
        $robot = $this->craftedItemsModel->find($robotId);

        if (!$robot) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Робот не найден.',
            ]);
        }

        // Вызываем метод для конкретного робота
        switch ($robot['name_eng']) {
            case 'RobotExplorer':
                $activator = new RobotExplorerActivator($robotId);
                break;
            case 'RobotGatherer':
                $activator = new RobotGathererActivator($robotId);
                break;
            // Добавьте здесь другие роботы и их классы активации
            default:
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Этот тип робота не поддерживается.',
                ]);
        }

        return $activator->activate($chatId, $character);
    }
}
