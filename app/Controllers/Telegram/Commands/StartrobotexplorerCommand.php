<?php

namespace App\Controllers\Telegram\Commands;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TaskModel;
use App\Models\TelegramUserModel;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use CodeIgniter\Log\Logger;
use Config\Services;

// <-- Добавляем:
use App\Services\Bases\BaseCheckService;  // наш новый сервис

class StartrobotexplorerCommand extends UserCommand
{
    protected $name = 'startrobotexplorer';
    protected $description = 'Start robot exploration with given coordinates.';
    protected $usage = '/startrobotexplorer x=100,y=500';
    protected $version = '1.1.0';

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
        $this->logger->debug('----- НАЧАЛО ОБРАБОТКИ КОМАНДЫ /startrobotexplorer -----');

        // 1) Получаем данные о сообщении
        $message  = $this->getMessage();
        $chatId   = $message->getChat()->getId();
        $userId   = $message->getFrom()->getId();
        $fullText = $message->getText();

        $this->logger->debug("Получено сообщение от пользователя: {$userId} в чате: {$chatId}");
        $this->logger->debug("Текст сообщения: {$fullText}");

        // 2) Извлекаем аргументы из полного текста сообщения
        $commandParts = explode(' ', $fullText, 2);
        $arguments    = $commandParts[1] ?? '';

        $this->logger->debug("Извлеченные аргументы: {$arguments}");

        // 3) Парсим формат "x=...,y=..."
        $pattern = '/^x=(\d+),y=(\d+)$/';
        if (!preg_match($pattern, $arguments, $matches)) {
            $help = "Пример команды: /startrobotexplorer x=100,y=500\nКоординаты от 1 до 1000. Без пробелов!";
            $this->logger->debug("Аргументы не соответствуют формату x=...,y=...");
            return $this->sendMessage($chatId, "Неверный формат ввода.\n{$help}");
        }
        $x = (int)$matches[1];
        $y = (int)$matches[2];

        $this->logger->debug("Извлеченные координаты: x={$x}, y={$y}");

        // Валидация x и y
        if ($x < 1 || $x > 1000 || $y < 1 || $y > 1000) {
            $this->logger->debug("Координаты x или y вне диапазона [1..1000].");
            return $this->sendMessage(
                $chatId,
                "Координаты должны быть в диапазоне [1..1000]. Получено x={$x}, y={$y}."
            );
        }

        // 4) Ищем пользователя Telegram
        $telegramUserModel = new TelegramUserModel();
        $telegramUser      = $telegramUserModel->where('telegram_id', $userId)->first();
        if (!$telegramUser) {
            $this->logger->error("Пользователь Telegram с ID: {$userId} не найден в базе данных.");
            return $this->sendMessage($chatId, "Не найден пользователь Telegram в базе. Сначала используйте /start!");
        }

        $this->logger->debug("Найден пользователь Telegram: " . print_r($telegramUser, true));

        // 5) Ищем персонажа
        $characterModel = new CharacterModel();
        $character      = $characterModel->where('telegram_user_id', $telegramUser['id'])->first();
        if (!$character) {
            $this->logger->error("Персонаж для пользователя Telegram с ID: {$userId} не найден.");
            return $this->sendMessage($chatId, "У вас нет персонажа. Сначала используйте /start, чтобы его создать.");
        }
        $characterId = $character['id'];
        $this->logger->debug("Найден персонаж: " . print_r($character, true));

        // --- NEW PART: проверяем, есть ли база и находится ли игрок на базе ---
        $baseCheckService = new BaseCheckService();
        $baseStatus       = $baseCheckService->checkBaseStatus($characterId);

        if (!$baseStatus['hasBase']) {
            // Если базы нет, прерываем:
            return $this->sendMessage($chatId,
                "У тебя нет базы! Нельзя запустить робота-исследователя, пока не построишь базу."
            );
        }
        if (!$baseStatus['isOnBase']) {
            // Если есть база, но персонаж не на базе, тоже прерываем
            return $this->sendMessage($chatId,
                "Ты не находишься на своей базе! Робота-исследователя запускаем только со своей базы."
            );
        }
        // --- END of base-check logic ---

        // 6) Проверяем наличие задачи "ExploringLocationRobot"
        $taskModel = new TaskModel();
        $taskRow   = $taskModel->where('name', 'ExploringLocationRobot')->first();
        if (!$taskRow) {
            $this->logger->debug("Задача ExploringLocationRobot не найдена.");
            return $this->sendMessage($chatId, "Не найдена задача ExploringLocationRobot.");
        }
        $taskId = $taskRow['id'];
        $this->logger->debug("Найдена задача ExploringLocationRobot с ID: {$taskId}");

        // 7) Проверяем, нет ли уже активной задачи
        $characterTaskModel = new CharacterTaskModel();
        $existingRobotTask  = $characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'in_work')
            ->first();
        if ($existingRobotTask) {
            $this->logger->debug("У персонажа уже есть активная задача ExploringLocationRobot.");
            return $this->sendMessage(
                $chatId,
                "У тебя уже запущен робот-исследователь! Дождись завершения предыдущего."
            );
        }

        // 8) Проверяем RoboticsWorkshop
        $buildingModel       = new BuildingModel();
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
            $this->logger->debug("Не найдено здание RoboticsWorkshop у персонажа.");
            return $this->sendMessage(
                $chatId,
                'У тебя нет построенной Мастерской робототехники, нельзя запустить робота-исследователя.'
            );
        }
        $workshopLevel = $roboticsWorkshop['level'] ?? 1;
        $this->logger->debug("Уровень мастерской: {$workshopLevel}");

        // 9) Ищем робот "Робот-исследователей" (ID=81)
        $robotId             = 81;
        $craftedItemsLogModel = new CraftedItemsLogModel();
        $robotLogEntry        = $craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();

        if (!$robotLogEntry) {
            $this->logger->debug("Робот-исследователь (ID={$robotId}) не найден или закончился.");
            return $this->sendMessage($chatId, "Нет роботов типа (ID={$robotId}) либо они закончились.");
        }
        $this->logger->debug("Робот-исследователь: " . print_r($robotLogEntry, true));

        // 9.1) Получаем baseDurability из таблицы crafted_items
        $robotItemModel = new CraftedItemsModel();
        $robotItemData  = $robotItemModel->find($robotId);
        if (!$robotItemData) {
            return $this->sendMessage(
                $chatId,
                "Не удалось найти описание робота #{$robotId} в crafted_items."
            );
        }
        $baseDurability = (int)$robotItemData['durability_count'];
        $this->logger->debug("baseDurability робота (ID={$robotId}): {$baseDurability}");

        // Текущее состояние робота(ов)
        $currentDurability = (int)$robotLogEntry['durability_count'];
        $currentQuantity   = (int)$robotLogEntry['quantity'];

        // 10) Время работы = 6 часов * уровень мастерской
        $hoursUntilBreakdown = 6 * $workshopLevel;
        $this->logger->debug("Время работы робота-исследователя: {$hoursUntilBreakdown} ч.");

        $requiredDurability = $hoursUntilBreakdown;

        // --- ЛОГИКА "ПОПОЛНЕНИЯ" (как в вашем коде) ---
        while ($currentDurability < $requiredDurability) {
            if ($currentQuantity > 1) {
                $this->logger->debug("Недостаточно прочности, но есть запасные роботы. Уменьшаем quantity, добавляем baseDurability.");
                $currentQuantity--;
                $currentDurability += $baseDurability;
            } else {
                // Остался 1 робот, прочности не хватает => частичный запуск
                $this->logger->debug("Нет запасных роботов, запускаем на текущую прочность = {$currentDurability}.");
                $requiredDurability = $currentDurability;
                break;
            }
        }

        // Контрольная проверка
        if ($currentDurability <= 0) {
            return $this->sendMessage($chatId, "Прочность робота равна 0, запуск невозможен.");
        }

        // Списываем
        $newDurability = $currentDurability - $requiredDurability;

        // Если робот "умер" полностью
        if ($newDurability <= 0) {
            $currentQuantity--;
            if ($currentQuantity <= 0) {
                $craftedItemsLogModel->delete($robotLogEntry['id']);
                $this->logger->debug("Все роботы (ID={$robotId}) израсходованы, запись удалена.");
            } else {
                $craftedItemsLogModel->update($robotLogEntry['id'], [
                    'quantity'         => $currentQuantity,
                    'durability_count' => $baseDurability,
                ]);
            }
        } else {
            // Иначе просто обновляем
            $craftedItemsLogModel->update($robotLogEntry['id'], [
                'quantity'         => $currentQuantity,
                'durability_count' => $newDurability,
            ]);
        }

        // 12) Создаём задачу исследования
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $hoursUntilBreakdown . 'H'));

        $taskSettings = json_encode([
            'coordinates'        => ['x' => $x, 'y' => $y],
            'duration_hours'     => $hoursUntilBreakdown,
            'exploration_radius' => $workshopLevel
        ]);

        $characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $telegramUser['id'],
            'task_id'          => $taskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => $taskSettings,
        ]);
        $this->logger->debug("Создана задача ExploringLocationRobot для персонажа ID={$characterId}");

        // 13) Подсчитываем остаток роботов
        $allRobotRows = $craftedItemsLogModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $robotId)
            ->findAll();

        $sumQuantity   = 0;
        $sumDurability = 0;
        foreach ($allRobotRows as $row) {
            $sumQuantity   += $row['quantity'];
            $sumDurability += $row['durability_count'] * $row['quantity'];
        }
        $this->logger->debug("Осталось роботов-исследователей: {$sumQuantity}, общая прочность: {$sumDurability}");

        // 14) Ответное сообщение
        $text = "🚀 *Ты запустил робота-исследователя с указанными координатами!* 🔍\n\n"
            . "Исследование продлится *{$hoursUntilBreakdown} ч.* (Уровень мастерской: {$workshopLevel}).\n"
            . "Робот стартует в точке: *X={$x}, Y={$y}*\n\n"
            . "Если исследование запущено с координатами, робот будет постепенно изучать все ячейки по кругу. 🔄✨\n\n"
            . "📉 Осталось:\n"
            . "  — Роботов: *{$sumQuantity}* шт.\n"
            . "  — Общая прочность: *{$sumDurability}*\n\n"
            . "🎉 Удачи в освоении новых территорий, отважный искатель приключений! 🗺️🛡️";

        $this->logger->debug('----- КОНЕЦ ОБРАБОТКИ КОМАНДЫ /startrobotexplorer -----');
        return $this->sendMessage($chatId, $text);
    }

    /**
     * Устанавливает аргументы для команды.
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
