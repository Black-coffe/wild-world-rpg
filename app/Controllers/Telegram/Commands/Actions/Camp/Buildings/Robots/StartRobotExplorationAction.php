<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

class StartRobotExplorationAction extends BaseAction
{
    protected $characterTaskModel;
    protected $craftedItemsLogModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterTaskModel = new CharacterTaskModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->buildingModel = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->taskModel = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        $characterId = $character['id'];
        $robotId = str_replace('startRobotExplorer_', '', $this->callbackQuery->getData());

        $roboticsWorkshopId = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first()['id'];
        $taskId = $this->taskModel->where('name', 'ExploringLocationRobot')->first()['id'];

        // Получаем уровень мастерской робототехники
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsWorkshopId)
            ->first();

        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // Получаем количество роботов и их использований
        $robotData = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->select('SUM(quantity) as total_quantity, SUM(durability_count * quantity) as total_durability')
            ->first();

        $totalQuantity = $robotData['total_quantity'] ?? 0;
        $totalDurability = $robotData['total_durability'] ?? 0;

        if ($totalQuantity <= 0) {
            return $this->sendError('У вас нет доступных роботов для запуска.');
        }

        // Рассчитываем время до поломки робота
        $hoursUntilBreakdown = $totalDurability * $workshopLevel;

        // Записываем задачу в базу данных
        $startTime = new \DateTime();
        $endTime = (clone $startTime)->add(new \DateInterval('PT' . $hoursUntilBreakdown . 'H'));

        $this->characterTaskModel->save([
            'character_id' => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id' => $taskId,
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'status' => 'in_work',
        ]);

        // Формируем ответное сообщение
        $text = "🚀 *Ты запустил робота Исследователя!* 🔍\n\n"
            . "🔧 Этот робот начнет изучение новых локаций и сохранит данные в базу открытий.\n\n"
            . "🔹 При текущем уровне \"Мастерская робототехники\" робот поломается и исчезнет через: *{$hoursUntilBreakdown}* часов\n\n"
            . "🎉 *Удачи в исследовании новых территорий твоим атвтоматизированным механизмом!* 🎉";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/robot_explorer.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendPhoto([
            'chat_id' => $chatId,
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text' => $message,
        ]);
    }
}
