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
 * Запуск процесса крафта "Рюкзак телепорт" (startCraftTeleportBackpack2).
 * Аналог класса StartCraftTeleportBeaconBasic2Action, но для предмета "Рюкзак телепорт".
 */
class StartCraftTeleportBackpack2Action extends BaseAction
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

        // 3) Проверка активного переезда (BaseRelocation), если используется
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse();
        }

        $characterId = $character['id'];

        /**
         * Здесь можно *повторить* проверки ресурсов/золота/зданий/пр.
         * (В случае, если кто-то пропустил предыдущее окно требований.)
         * Но предположим, что проверка была в TeleportBackpack2Action,
         * и теперь мы только "списываем" и запускаем задачу.
         */

        // Примерные требования к крафту рюкзака телепорт (должны совпадать с TeleportBackpack2Action):
        $requiredResources = [
            'Янтарь'          => 23,
            'Минералы'        => 15,
            'Солнечные камни' => 12,
            'Кристаллы'       => 7,
        ];
        $requiredComponents = [
            'Проводка'               => 4,
            'Электронные компоненты' => 8,
            'Ткань'                  => 36,
        ];
        $requiredGold = 21000;

        // 4) Проверяем / создаём запись в tasks (например, craftTeleportBackpack)
        $taskRow = $this->taskModel->where('name', 'craftTeleportBackpack')->first();
        if (!$taskRow) {
            // Если нет, создаём
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftTeleportBackpack',
                'name_rus'                   => 'Крафт рюкзака телепорта',
                'description'                => 'Задача крафта рюкзака для мгновенного возвращения на базу',
                'min_duration'               => 40,   // пример
                'max_duration'               => 60,   // пример
                'type'                       => 'craft',
                'difficulty_level'           => 8,    // чуть сложнее, чем beacon
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,    // разрешён ли параллельный крафт?
                'interruptible'              => 1,
                'created_at'                 => date('Y-m-d H:i:s'),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ]);
            if (!$newTaskId) {
                return $this->sendError($chatId, "Не удалось автоматически создать задачу 'craftTeleportBackpack'.");
            }
            $taskRow = $this->taskModel->find($newTaskId);
            if (!$taskRow) {
                return $this->sendError($chatId, "Ошибка после создания 'craftTeleportBackpack'. Не удалось найти в БД.");
            }
        }

        // 5) Проверяем, нет ли уже запущенной (in_work) задачи крафта рюкзака у игрока
        $activeTask = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                "У тебя уже есть активная задача крафта рюкзака телепорт. Дождись её завершения!"
            );
        }

        // 6) Создаём задачу для крафта, задаём, к примеру, 45 минут
        $durationMinutes = 45;
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

        // 7) Списываем ресурсы/предметы/золото
        $this->decrementResources($characterId, $requiredResources);
        $this->decrementCraftedItems($characterId, $requiredComponents);
        $this->characterModel
            ->where('id', $characterId)
            ->set('gold', "gold - {$requiredGold}", false)
            ->update();

        // 8) Уведомляем об успехе
        return $this->notifyCraftStarted($chatId, $durationMinutes);
    }

    // ---------------------------
    // Методы списания / уведомлений
    // ---------------------------

    /**
     * Списание обычных (сырьевых) ресурсов
     */
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

    /**
     * Списание крафтовых предметов
     */
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

    /**
     * Шаблон для вывода статуса запуска крафта
     */
    private function notifyCraftStarted(int $chatId, int $minutes): ServerResponse
    {
        $text = "*Процесс крафта запущен!*\n\n"
            . "Ты создаёшь: *🎒 Рюкзак телепорт*.\n"
            . "__Время крафта__: ~{$minutes} минут.\n\n"
            . "По завершении рюкзак будет добавлен в твой инвентарь.";

        $imagePath = base_url('uploads/telegram/craft/standard/backpack_craft.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Выводит сообщение об ошибке.
     */
    private function sendError(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $message,
        ]);
    }
}
