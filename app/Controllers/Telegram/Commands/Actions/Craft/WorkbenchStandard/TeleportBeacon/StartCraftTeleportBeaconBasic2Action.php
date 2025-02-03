<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\TeleportBeacon;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Запуск процесса крафта "Базовый телепорт-маяк" (с автосозданием записи в tasks, если её не было).
 */
class StartCraftTeleportBeaconBasic2Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $characterModel;
    protected $buildingModel;
    protected $characterBuildingModel;
    protected $claimedCellModel;
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;
    protected $characterTaskModel;
    protected $taskModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel  = new CharacterResourceModel();
        $this->resourceModel           = new ResourceModel();
        $this->characterModel          = new CharacterModel();
        $this->buildingModel           = new BuildingModel();
        $this->characterBuildingModel  = new CharacterBuildingModel();
        $this->claimedCellModel        = new ClaimedCellModel();
        $this->craftedItemsLogModel    = new CraftedItemsLogModel();
        $this->craftedItemsModel       = new CraftedItemsModel();
        $this->characterTaskModel      = new CharacterTaskModel();
        $this->taskModel               = new TaskModel();
    }

    public function handle(): ServerResponse
    {
        // 1) "Снимаем часики" с нажатой кнопки
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 2) Получаем user/character
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь или персонаж не найдены.',
            ]);
        }

        // 3) Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        $characterId = $character['id'];

        // (При желании) Повторить проверки ресурсов/золота/зданий,
        // ... но тут опустим: предполагаем, что мы уже это сделали в предыдущем классе.

        // Для примера снова укажем требования (чтобы списать ресурсы):
        $requiredResources = [
            'Угольная порода'   => 10,
            'Железная руда'     => 8,
            'Редкие металлы'    => 12,
        ];
        $requiredComponents = [
            'Проводка'                => 26,
            'Электронные компоненты'  => 4,
            'Ткань'                   => 8,
        ];
        $requiredGold = 12500;

        // 4) Проверяем/создаём запись в tasks
        $taskRow = $this->taskModel->where('name', 'craftTeleportBeaconBasic')->first();
        if (!$taskRow) {
            // Если нет, пробуем создать новую строку в таблице tasks
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftTeleportBeaconBasic',
                'name_rus'                   => 'Крафт телепорта маяка (базовый)',
                'description'                => 'Задача крафта маяка телепорта для быстрого перемещения',
                'min_duration'               => 30,
                'max_duration'               => 45,
                'type'                       => 'craft',
                'difficulty_level'           => 6,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at'                 => date('Y-m-d H:i:s'),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ]);

            if (!$newTaskId) {
                return $this->sendError($chatId, "Не удалось автоматически создать задачу 'craftTeleportBeaconBasic'.");
            }

            // Загружаем только что созданную запись
            $taskRow = $this->taskModel->find($newTaskId);
            if (!$taskRow) {
                return $this->sendError($chatId, "Ошибка после создания задачи 'craftTeleportBeaconBasic'. Не удалось её найти в БД.");
            }
        }

        // 5) Проверяем, нет ли уже запущенной (in_work) такой задачи у игрока
        $activeTask = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError($chatId,
                "У тебя уже есть активная задача крафта этого маяка. Дождись её завершения."
            );
        }

        // 6) Создаём задачу (character_tasks), указываем время 30 минут (пример).
        $durationMinutes = 30;
        $startTime       = new \DateTime();
        $endTime         = (clone $startTime)->add(new \DateInterval('PT' . $durationMinutes . 'M'));

        $this->characterTaskModel->insert([
            'character_id'     => $characterId,
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
        ]);

        // 7) Списание ресурсов и т. д.
        $this->decrementResources($characterId, $requiredResources);
        $this->decrementCraftedItems($characterId, $requiredComponents);
        $this->characterModel
            ->where('id', $characterId)
            ->set('gold', "gold - {$requiredGold}", false)
            ->update();

        // 8) Отправляем сообщение об успехе
        return $this->notifyCraftStarted($chatId, $durationMinutes);
    }

    // ---------------------------
    // Методы списания / уведомлений
    // ---------------------------

    private function decrementResources(int $characterId, array $requiredResources)
    {
        foreach ($requiredResources as $name => $qty) {
            $resourceRow = $this->resourceModel->getResourceByName($name);
            if ($resourceRow) {
                $this->characterResourceModel
                    ->where('id_characters', $characterId)
                    ->where('id_resources',  $resourceRow['id'])
                    ->set('quantity', 'quantity - ' . $qty, false)
                    ->update();
            }
        }
    }

    private function decrementCraftedItems(int $characterId, array $requiredComponents)
    {
        foreach ($requiredComponents as $itemName => $qty) {
            $itemRow = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if ($itemRow) {
                $this->craftedItemsLogModel
                    ->where('character_id',    $characterId)
                    ->where('crafted_item_id', $itemRow['id'])
                    ->set('quantity', 'quantity - ' . $qty, false)
                    ->update();
            }
        }
    }

    private function notifyCraftStarted(int $chatId, int $minutes): ServerResponse
    {
        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: *🌀 Базовый телепорт-маяк*.\n"
            . "__Время крафта__: ~{$minutes} минут.\n\n"
            . "По завершении ты получишь маяк в свой инвентарь.";

        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/default_beacon.jpg');
        }

        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendError(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $message,
        ]);
    }

    private function sendInsufficientResponse(int $chatId, string $message): ServerResponse
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '💰 Продать',   'callback_data' => 'sell'],
                    ['text' => '🛍️ Купить',   'callback_data' => 'buy'],
                ],
            ]
        ];

        $imagePath = base_url('uploads/telegram/craft/standard/beacon_craft.jpg');
        if (!file_exists($imagePath)) {
            $imagePath = base_url('uploads/telegram/craft/standard/default_beacon.jpg');
        }

        return Request::sendPhoto([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    // ---------------------------
    // Дополнительные проверки
    // ---------------------------
    private function checkHasBase(int $characterId): bool
    {
        $row = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();
        return (bool) $row;
    }
}
