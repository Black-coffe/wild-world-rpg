<?php


namespace App\Controllers\Telegram\Commands\Actions\Camp;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\HandPumpHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\BlastFurnaceHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RoboticsWorkshopHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GreenhouseHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GymHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\LaboratoryHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\SolarStationHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WarehouseHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WorkshopHandler;

class BuildingHandlerAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Отправляем ответ на CallbackQuery сразу
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $callbackData = $this->callbackQuery->getData();
        [$prefix, $buildingId, $buildingNameEng] = explode('_', $callbackData);

        // Проверка на корректность коллбека
        if ($prefix !== 'building' || !is_numeric($buildingId) || empty($buildingNameEng)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Некорректные данные для обработки запроса.',
            ]);
        }

        switch ($buildingNameEng) {
            case 'HandPump':
                $handler = new HandPumpHandler($this->callbackQuery);
                break;
            case 'BlastFurnace':
                $handler = new BlastFurnaceHandler($this->callbackQuery);
                break;
            case 'RoboticsWorkshop':
                $handler = new RoboticsWorkshopHandler($this->callbackQuery);
                break;
            case 'Workshop':
                $handler = new WorkshopHandler($this->callbackQuery);
                break;
            case 'Warehouse':
                $handler = new WarehouseHandler($this->callbackQuery);
                break;
            case 'Laboratory':
                $handler = new LaboratoryHandler($this->callbackQuery);
                break;
            case 'Greenhouse':
                $handler = new GreenhouseHandler($this->callbackQuery);
                break;
            case 'SolarStation':
                $handler = new SolarStationHandler($this->callbackQuery);
                break;
            case 'Gym':
                $handler = new GymHandler($this->callbackQuery);
                break;
            default:
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text' => 'Неизвестное строение.',
                ]);
        }

        return $handler->handle();
    }
}

