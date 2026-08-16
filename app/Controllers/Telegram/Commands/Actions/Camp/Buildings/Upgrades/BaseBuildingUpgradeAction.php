<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Upgrades;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CharacterBuildingModel;
use App\Models\BuildingModel;
use App\Models\CharacterModel;

/**
 * Абстрактный класс с общей логикой:
 * - парсит колбэк "upgrade_building_{id}"
 * - находит запись character_building
 * - загружает currentLevel, и т.д.
 * Потом вызывает методы checkUpgradeConditions() / applyUpgradeCost() / onUpgradeSuccess()
 */
abstract class BaseBuildingUpgradeAction extends BaseAction
{
    protected $characterBuildingModel;
    protected $buildingModel;
    protected $characterModel;

    protected $buildingId;         // из callback_data
    protected $characterBuilding;  // запись из character_buildings
    protected $buildingInfo;       // запись из buildings
    protected $character;          // текущий персонаж

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
        $this->characterModel         = new CharacterModel();
    }

    /**
     * Точка входа: основной метод
     */
    public function handle(): ServerResponse
    {
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найден.',
            ]);
        }
        $this->character = $character;

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        // Парсим callback_data вида: "upgrade_building_9"
        $parts = explode('_', $callbackData);
        $this->buildingId = $parts[2] ?? null;

        if (!$this->buildingId) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректный ID здания.',
            ]);
        }

        // 1. Проверяем, есть ли у персонажа это здание
        $this->characterBuilding = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $this->buildingId)
            ->first();

        if (!$this->characterBuilding) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет такого здания на базе.',
            ]);
        }

        // 2. Инфа о самом здании
        $this->buildingInfo = $this->buildingModel->find($this->buildingId);
        if (!$this->buildingInfo) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о здании не найдена.',
            ]);
        }

        // Теперь вызываем метод-проверку (чтобы дочерний класс реализовал условия)
        $checkResult = $this->checkUpgradeConditions();
        if ($checkResult !== true) {
            // Если вернулась строка, значит это сообщение об ошибке
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $checkResult, // текст причины, почему нельзя апгрейдить
            ]);
        }

        // Списываем ресурсы, деньги и т.д.
        $applyResult = $this->applyUpgradeCost();
        if ($applyResult !== true) {
            // Если вернулась строка - сообщение об ошибке
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $applyResult,
            ]);
        }

        // Повышаем уровень
        $newLevel = $this->characterBuilding['level'] + 1;
        $this->characterBuildingModel->update($this->characterBuilding['id'], [
            'level' => $newLevel,
        ]);

        // Вызываем "хук" на случай, если надо выполнить дополнительную логику
        $this->onUpgradeSuccess($newLevel);

        // Ответ пользователю
        $text = sprintf(
            "Здание *%s* теперь имеет %d уровень!\n\nПоздравляем!",
            $this->buildingInfo['name_ru'],
            $newLevel
        );

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Абстрактные методы, которые каждый потомок реализует под свои условия
     */
    abstract protected function checkUpgradeConditions();
    abstract protected function applyUpgradeCost();

    /**
     * Если нужно выполнить доп. логику после успешного апгрейда,
     * можно переопределить этот метод в дочернем классе
     */
    protected function onUpgradeSuccess($newLevel)
    {
        // Пусто по умолчанию
    }
}
