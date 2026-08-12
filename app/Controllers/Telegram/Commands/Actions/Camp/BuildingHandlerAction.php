<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

// Подключаем обработчики других строений
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\HandPumpHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\BlastFurnaceHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\RoboticsWorkshopHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GreenhouseHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\GymHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\LaboratoryHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\SolarStationHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WarehouseHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\WorkshopHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportationCenterHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ArsenalHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\CommunicationTowerHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\DefensiveBuildingHandler;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\LeanToHandler;

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
            case 'TeleportationCenter':
                $handler = new TeleportationCenterHandler($this->callbackQuery);
                break;
            case 'Arsenal':
                $handler = new ArsenalHandler($this->callbackQuery);
                break;
            case 'CommunicationTower':
                $handler = new CommunicationTowerHandler($this->callbackQuery);
                break;
            // ADR-041: оборонные постройки (раньше падали в default → dead-end).
            case 'WoodenWall':
            case 'BarbedFence':
            case 'WatchTower':
                $handler = new DefensiveBuildingHandler($this->callbackQuery);
                break;

            // S5 (ADR-142): «Навес» — ПЕРВАЯ постройка новичка. Case забыли завести,
            // когда добавляли здание, → падал в default. Багрепорт из Bugs-info
            // (12.08.2026, Анжела): тап по «⛺ Навес L1» отвечал «Неизвестное строение».
            // Дофаминовый момент онбординга оборачивался ошибкой у всех 21 владельцев.
            case 'LeanTo':
                $handler = new LeanToHandler($this->callbackQuery);
                break;

            default:
                // Сюда попадать больше не должно: гейт
                // tests/unit/Camp/BuildingHandlerCoverageTest требует case на каждый
                // ключ Config\Buildings. Но если постройка всё же не опознана —
                // не бросаем игрока в тупик, а возвращаем на базу.
                return Request::sendMessage([
                    'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'         => "🏚 Не удалось открыть это строение.\n\n"
                        . 'Оно есть на базе, но его экран пока не готов — мы уже знаем об этом. '
                        . 'Вернись на базу и попробуй другое сооружение.',
                    'reply_markup' => json_encode(['inline_keyboard' => [[
                        ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ]]]),
                ]);
        }

        // Выполняем handle() конкретного обработчика
        return $handler->handle();
    }
}
