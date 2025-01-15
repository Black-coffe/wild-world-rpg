<?php

namespace App\Controllers\Telegram\Commands;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TaskModel;
use App\Models\TelegramUserModel;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use CodeIgniter\Log\Logger;
use Config\Services;

class StartrobotexplorerCommand extends UserCommand
{
    protected $name = 'startrobotexplorer';
    protected $description = 'Start robot exploration with given coordinates.';
    protected $usage = '/startrobotexplorer <new_name>';
    protected $version = '1.0.0';

    /**
     * @var string
     */
    protected $arguments = '';

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct($telegram, $update)
    {
        parent::__construct($telegram, $update);
        $this->logger = Services::logger();
    }


    public function execute(): ServerResponse
    {
        $this->logger->debug('----- НАЧАЛО ОБРАБОТКИ КОМАНДЫ /startrobotexpl -----');

        // 1) Получаем данные о сообщении
        $message = $this->getMessage();
        $chatId  = $message->getChat()->getId();
        $userId  = $message->getFrom()->getId();
        $fullText = $message->getText();

        $this->logger->debug("Получено сообщение от пользователя: {$userId} в чате: {$chatId}");
        $this->logger->debug("Текст сообщения: {$fullText}");

        // 2) Извлекаем аргументы из полного текста сообщения
        $commandParts = explode(' ', $fullText, 2);
        $arguments = $commandParts[1] ?? '';

        $this->logger->debug("Извлеченные аргументы: {$arguments}");

        // 3) Парсим формат "x=...,y=..."
        $pattern = '/^x=(\d+),y=(\d+)$/';
        if (!preg_match($pattern, $arguments, $matches)) {
            $help = "Пример команды: `/startrobotexplorer x=100,y=500`\n"
                . "Координаты от 1 до 1000. Без пробелов!";
            $this->logger->debug("Аргументы не соответствуют формату x=...,y=...");
            return $this->sendMessage($chatId, "Неверный формат ввода.\n{$help}");
        }
        $x = (int)$matches[1];
        $y = (int)$matches[2];

        $this->logger->debug("Извлеченные координаты: x={$x}, y={$y}");

        // Валидация x и y
        if ($x < 1 || $x > 1000 || $y < 1 || $y > 1000) {
            $this->logger->debug("Координаты x или y находятся вне допустимого диапазона [1..1000].");
            return $this->sendMessage($chatId,
                "Координаты должны быть целыми числами в диапазоне [1..1000]. Получено x={$x},y={$y}."
            );
        }

        // 4) Ищем пользователя Telegram
        $telegramUserModel = new TelegramUserModel();
        $telegramUser = $telegramUserModel->where('telegram_id', $userId)->first();
        if (!$telegramUser) {
            $this->logger->error("Пользователь Telegram с ID: {$userId} не найден в базе данных.");
            return $this->sendMessage($chatId, "Не найден пользователь Telegram в базе. Сначала используйте /start!");
        }

        $this->logger->debug("Найден пользователь Telegram: " . print_r($telegramUser, true));

        // 5) Ищем персонажа
        $characterModel = new CharacterModel();
        $character = $characterModel->where('telegram_user_id', $telegramUser['id'])->first();
        if (!$character) {
            $this->logger->error("Персонаж для пользователя Telegram с ID: {$userId} не найден.");
            return $this->sendMessage($chatId, "У вас нет персонажа. Сначала введите /start, чтобы его создать.");
        }
        $characterId = $character['id'];

        $this->logger->debug("Найден персонаж: " . print_r($character, true));

        // 4) Проверяем наличие задачи "ExploringLocationRobot"
        $taskModel = new TaskModel();
        $taskRow = $taskModel->where('name', 'ExploringLocationRobot')->first();
        if (!$taskRow) {
            $this->logger->debug("Задача ExploringLocationRobot не найдена.");
            return $this->sendMessage($chatId, "Не найдена задача ExploringLocationRobot.");
        }
        $taskId = $taskRow['id'];
        $this->logger->debug("Найдена задача ExploringLocationRobot с ID: {$taskId}");

        // 5) Проверяем, нет ли уже активной задачи
        $characterTaskModel = new CharacterTaskModel();
        $existingRobotTask = $characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();
        if ($existingRobotTask) {
            $this->logger->debug("У персонажа уже есть активная задача ExploringLocationRobot.");
            return $this->sendMessage($chatId,
                "У тебя уже запущен робот-исследователь! Дождись завершения предыдущего."
            );
        }

        // 6) Проверяем RoboticsWorkshop
        $buildingModel = new BuildingModel();
        $roboticsWorkshopRow = $buildingModel->where('name_en', 'RoboticsWorkshop')->first();
        if (!$roboticsWorkshopRow) {
            $this->logger->debug("Здание RoboticsWorkshop не найдено в справочнике.");
            return $this->sendMessage($chatId, 'Не найдено здание RoboticsWorkshop в справочнике.');
        }
        $roboticsWorkshopId = $roboticsWorkshopRow['id'];
        $this->logger->debug("Найдено здание RoboticsWorkshop с ID: {$roboticsWorkshopId}");

        $characterBuildingModel = new CharacterBuildingModel();
        $roboticsWorkshop = $characterBuildingModel
            ->where('character_id', $characterId)
            ->where('building_id', $roboticsWorkshopId)
            ->first();
        if (!$roboticsWorkshop) {
            $this->logger->debug("У персонажа не найдено здание RoboticsWorkshop.");
            return $this->sendMessage($chatId,
                'У тебя нет построенной Мастерской робототехники, нельзя запустить робота-исследователя.'
            );
        }
        $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        $this->logger->debug("Уровень здания RoboticsWorkshop: {$workshopLevel}");

        // 7) Ищем робот "Робот-исследователь"
        $robotId = 81;
        $craftedItemsLogModel = new CraftedItemsLogModel();
        $robotLogEntry = $craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->where('durability_count >', 0)
            ->orderBy('id', 'ASC')
            ->first();

        if (!$robotLogEntry) {
            $this->logger->debug("Робот-исследователь с ID: {$robotId} не найден или закончился.");
            return $this->sendMessage($chatId,
                "Нет роботов нужного типа (ID={$robotId}) либо они закончились."
            );
        }
        $this->logger->debug("Найден робот-исследователь: " . print_r($robotLogEntry, true));

        $currentDurability = $robotLogEntry['durability_count'];
        $currentQuantity   = $robotLogEntry['quantity'];
        if ($currentDurability <= 0) {
            $this->logger->debug("У робота-исследователя прочность равна 0.");
            return $this->sendMessage($chatId,
                "Этот робот не имеет прочности (0). Нельзя запустить."
            );
        }

        // 8) Списываем 1 durability_count
        $newDurability = $currentDurability - 1;
        if ($newDurability <= 0) {
            // робот "умер", уменьшаем quantity
            $newQuantity = $currentQuantity - 1;
            if ($newQuantity <= 0) {
                // удаляем
                $craftedItemsLogModel->delete($robotLogEntry['id']);
                $this->logger->debug("Робот-исследователь с ID: {$robotId} израсходовал всю прочность и был удален. Оставшееся количество: 0");
            } else {
                $craftedItemsLogModel->update($robotLogEntry['id'], [
                    'quantity' => $newQuantity,
                    'durability_count' => 50 // восстанавливаем остальным
                ]);
                $this->logger->debug("Робот-исследователь с ID: {$robotId} израсходовал всю прочность. Оставшееся количество: {$newQuantity}, прочность восстановлена до 50");
            }
        } else {
            // просто уменьшаем прочность
            $craftedItemsLogModel->update($robotLogEntry['id'], [
                'durability_count' => $newDurability
            ]);
            $this->logger->debug("У робота-исследователя с ID: {$robotId} списана 1 единица прочности. Текущая прочность: {$newDurability}");
        }

        // 9) Считаем время работы: 6 * workshopLevel
        $hoursUntilBreakdown = 6 * $workshopLevel;
        $this->logger->debug("Время работы робота-исследователя: {$hoursUntilBreakdown} часов.");

        // 10) Создаём задачу
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $hoursUntilBreakdown . 'H'));

        // Координаты x,y запишем в поле task_settings, например "100,500"
        $coordsString = "{$x},{$y}";

        $characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUser['id'],  // user['id']
            'task_id'          => $taskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => $coordsString, // Сюда кладём координаты
        ]);

        $this->logger->debug("Создана задача ExploringLocationRobot для персонажа с ID: {$characterId}");

        // 11) Подсчитаем остатки
        $allRobotRows = $craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->findAll();

        $sumQuantity   = 0;
        $sumDurability = 0;
        foreach ($allRobotRows as $row) {
            $sumQuantity   += $row['quantity'];
            $sumDurability += ($row['durability_count'] * $row['quantity']);
        }
        $this->logger->debug("Остаток роботов-исследователей: {$sumQuantity} шт., общая прочность: {$sumDurability}");

        // 12) Итоговое сообщение
        $text = "🚀 *Ты запустил робота-исследователя с координат!* 🔍\n\n"
            . "Запуск продлится *{$hoursUntilBreakdown} ч.* (Мастерская: {$workshopLevel} ур.).\n"
            . "Робот начинает в точке: *X={$x}, Y={$y}*\n\n"
            . "📉 Осталось:\n"
            . "  — Роботов: *{$sumQuantity}* шт.\n"
            . "  — Общих часов поиска: *{$sumDurability}*\n\n"
            . "🎉 Удачи в исследовании новых территорий!";

        $this->logger->debug('----- КОНЕЦ ОБРАБОТКИ КОМАНДЫ /startrobotexpl -----');

        return $this->sendMessage($chatId, $text);
    }

    /**
     * Устанавливает аргументы для команды.
     *
     * @param string $arguments
     * @return $this
     */
    public function setArguments(string $arguments): self
    {
        $this->arguments = $arguments;
        return $this;
    }


    /**
     * Утилита для отправки текстового сообщения в Telegram
     */
    private function sendMessage(int $chatId, string $text): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}