<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Models\CraftedItemsLogModel;
use App\Models\CharacterBuildingModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class RobotGathererActivator implements RobotActivatorInterface
{
    protected $craftedItemsLogModel;
    protected $characterBuildingModel;
    protected $robotId;

    public function __construct($robotId)
    {
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->robotId = $robotId;
    }

    public function activate($chatId, $character): ServerResponse
    {
        // Логика активации робота добытчика
        $characterId = $character['id'];

        // Пример логики для активации робота добытчика
        $text = "Вы активировали робота ⛏️ Добытчика!\n"
            . "Робот начинает собирать ресурсы и приносить их на базу.";

        // Логика, связанная с активацией робота, например, уменьшение количества роботов, обновление базы данных и т.д.

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
