<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Класс, в который попадает пользователь после нажатия:
 * "📍 Указать координаты запуска".
 * Он проверяет, есть ли ещё роботы, не запущена ли уже задача "ExploringLocationRobot",
 * есть ли в справочнике RoboticsWorkshop, и если всё ок,
 * даёт инструкцию, как ввести координаты в чат.
 */
class SetCoordinatesRobotExplorerAction extends BaseAction
{
    protected $characterTaskModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->taskModel              = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        if (!$user || !$character) {
            return $this->sendError("Пользователь или персонаж не найден. Невозможно указать координаты.");
        }
        $characterId = $character['id'];

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // 2) Ищем в справочнике задачу "ExploringLocationRobot"
        $taskRow = $this->taskModel->where('name', 'ExploringLocationRobot')->first();
        if (!$taskRow) {
            return $this->sendError("Не найдена задача ExploringLocationRobot.");
        }
        $taskId = $taskRow['id'];

        // Проверяем, нет ли уже активной задачи
        $existingRobotTask = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();
        if ($existingRobotTask) {
            return $this->sendError(
                "У тебя уже запущен робот-исследователь! "
                . "Дождись завершения предыдущего, прежде чем задавать новые координаты."
            );
        }

        // 3) Проверяем, есть ли здание RoboticsWorkshop в справочнике
        $roboticsWorkshopRow = $this->buildingModel->where('name_en', 'RoboticsWorkshop')->first();
        if (!$roboticsWorkshopRow) {
            return $this->sendError('Ошибка: не найдено здание RoboticsWorkshop в справочнике.');
        }
        $roboticsWorkshopId = $roboticsWorkshopRow['id'];

        // 4) Проверяем, есть ли у персонажа построенная мастерская (building_id = roboticsWorkshopId)
        $roboticsWorkshop = $this->characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsWorkshopId)
            ->first();
        if (!$roboticsWorkshop) {
            return $this->sendError(
                'У тебя нет построенной Мастерской робототехники, '
                . 'значит нельзя запускать робота-исследователя.'
            );
        }
        $workshopLevel = $roboticsWorkshop['level'] ?? 1;

        // 5) Из callback_data извлекаем robotId
        //    setCoordinates_<robotId>, напр. setCoordinates_81 => robotId=81
        $callbackData = $this->callbackQuery->getData();
        $robotId = str_replace('setCoordinatesRobotExplorer_', '', $callbackData);

        // 6) Проверяем, есть ли у пользователя роботы этого типа (в crafted_items_log)
        //    и quantity > 0
        $robotLogEntry = $this->craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();

        if (!$robotLogEntry) {
            // Записи либо нет, либо quantity=0
            return $this->sendError("У тебя нет роботов данного типа. Возможно, они закончились или не были скрафчены.");
        }

        // Проверяем прочность (durability_count)
        if ($robotLogEntry['durability_count'] <= 0) {
            return $this->sendError("У твоего робота уже 0 прочности. Нельзя запустить.");
        }

        // 7) Если дошли сюда — всё ок, отправляем инструкцию
        $text = "Отлично! Сейчас мы зададим координаты старта для робота.\n\n"
            . "Чтобы задать координаты, **напиши в чат** специальную команду:\n\n"
            . "`/startrobotexplorer x=321,y=543`\n\n"
            . "⚠ *Важно:* Не меняй структуру, пробелы или текст. Просто поправь числа 321 и 543 на нужные!\n"
            . "Смотри на карту мира для правильных координат.\n\n"
            . "Например, `/startrobotexplorer x=100,y=500` — если хочешь начать в X=100, Y=500.\n\n"
            . "Как только отправишь команду, робот будет запущен с этих координат!";

        // Кнопка «Назад» (например, к списку роботов или другому меню)
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '◀ Назад', 'callback_data' => 'AllRobots']
                ],
            ]
        ];

        // Сбрасываем «анимацию» нажатия
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Утилитарный метод для отправки ошибки.
     */
    private function sendError($message): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $message,
        ]);
    }
}
