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
use App\Services\Telegram\Request;

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

        // Компоненты проверяем ДО создания задачи и любых списаний (фикс 2026-08-06):
        // иначе при устаревшей кнопке снимутся золото и сырьё, а компонент — нет.
        // Story craft-shortfall-buy-13: вместо текстового отказа — общий экран
        // нехватки (CraftShortageService), как остальной крафт.
        $missingComponents = $this->missingComponents($characterId, $requiredComponents);
        if ($missingComponents !== []) {
            return $this->shortageScreen($character, [], $missingComponents, [
                'item_name_rus'         => 'Рюкзак телепорт',
                'info_callback'         => 'teleportBackpack2',
                'resources'             => $requiredResources,
                'crafted_items'         => $this->componentsByEng($requiredComponents),
                // fix-07 (остаток критической находки 1 ревью): рецепта нет в
                // Config\CraftRecipes — ключ докупки берём из собственного
                // callback_data старта ('startCraftTeleportBackpack2'), тот же
                // формат, что и у остальных ~105 рецептов. Количество здесь всегда
                // 1 — у этого рецепта нет своей "×N" ветки.
                'craft_again_callback' => 'genericCraft_TeleportBackpack2_1',
            ], $chatId);
        }

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
        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        return $this->notifyCraftStarted($chatId, $durationMinutes, $background);
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
    /**
     * Хватает ли компонентов ПРЯМО СЕЙЧАС (экран требований мог быть открыт час назад,
     * а кнопка в чате живёт вечно). Без этой проверки списание ушло бы наполовину:
     * золото и сырьё сняты, а `deductCraftedItem` отказал по остатку.
     *
     * @param array<string,int> $requiredComponents
     * @return array<string,array{need:int,have:int,name:string}> name_eng → нехватка
     */
    private function missingComponents(int $characterId, array $requiredComponents): array
    {
        $missing = [];
        foreach ($requiredComponents as $itemName => $qty) {
            $itemRow = (new CraftedItemsModel())->getCraftedItemByName($itemName);
            $itemId  = (is_array($itemRow) && is_numeric($itemRow['id'] ?? null)) ? (int) $itemRow['id'] : 0;
            $itemEng = (is_array($itemRow) && is_string($itemRow['name_eng'] ?? null)) ? $itemRow['name_eng'] : $itemName;
            $have    = 0;
            if ($itemId > 0) {
                $log  = (new CraftedItemsLogModel())
                    ->where('crafted_item_id', $itemId)
                    ->where('character_id', $characterId)
                    ->first();
                $have = (is_array($log) && is_numeric($log['quantity'] ?? null)) ? (int) $log['quantity'] : 0;
            }
            if ($have < (int) $qty) {
                $missing[$itemEng] = ['need' => (int) $qty, 'have' => $have, 'name' => $itemName];
            }
        }

        return $missing;
    }

    /**
     * Полная (не только недостающая) карта компонентов рецепта, ключ — name_eng.
     * Нужна `CraftShortfallBuyService::quote()` для расчёта доли рецепта деньгами.
     *
     * @param array<string,int> $requiredComponents
     * @return array<string,int>
     */
    private function componentsByEng(array $requiredComponents): array
    {
        $out = [];
        foreach ($requiredComponents as $itemName => $qty) {
            $itemRow = (new CraftedItemsModel())->getCraftedItemByName($itemName);
            $itemEng = (is_array($itemRow) && is_string($itemRow['name_eng'] ?? null)) ? $itemRow['name_eng'] : $itemName;
            $out[$itemEng] = (int) $qty;
        }

        return $out;
    }

    /**
     * Списание крафт-компонентов.
     *
     * 🔴 Фикс 2026-08-06 (замер на testbot): прежний код звал
     * `CraftedItemsLogModel::update()` с raw-выражением `set('quantity','quantity - N', false)`.
     * Такой вызов у ЭТОЙ модели молча возвращает `false` и запрос вообще не уходит —
     * сырьё и золото списывались, а Проводка / Электронные компоненты / Ткань
     * оставались нетронутыми, то есть рецепт был дешевле задуманного. Ни ошибки,
     * ни лога: результат `update()` никто не проверял.
     *
     * Канонический путь — `deductCraftedItem()` (транзакция + проверка остатка +
     * удаление опустевшей строки). Результат проверяем и логируем: молчаливое
     * «списал» — это тихий эксплойт (memory feedback_crafted_items_log_raw_set_update_noop).
     */
    private function decrementCraftedItems(int $characterId, array $requiredComponents)
    {
        foreach ($requiredComponents as $itemName => $qty) {
            // Свежие инстансы на итерацию — CI4 builder копит where()
            // (memory feedback_ci4_model_builder_state_quirk).
            $itemRow = (new CraftedItemsModel())->getCraftedItemByName($itemName);
            $itemId  = (is_array($itemRow) && is_numeric($itemRow['id'] ?? null)) ? (int) $itemRow['id'] : 0;
            if ($itemId <= 0) {
                log_message('error', "[Craft] компонент «{$itemName}» не найден в crafted_items — списание пропущено.");

                continue;
            }

            if (! (new CraftedItemsLogModel())->deductCraftedItem($itemId, $characterId, (int) $qty)) {
                log_message('error', "[Craft] не удалось списать «{$itemName}» x{$qty} у персонажа {$characterId}.");
            }
        }
    }

    /**
     * Шаблон для вывода статуса запуска крафта
     */
    private function notifyCraftStarted(int $chatId, int $minutes, bool $background): ServerResponse
    {
        $scope = new \App\Services\Tasks\ActionScopeService();
        $text = "*Процесс крафта запущен!*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Ты создаёшь: *🎒 Рюкзак телепорт*.\n"
            . "__Время крафта__: ~{$minutes} минут.\n\n"
            . "По завершении рюкзак будет добавлен в твой инвентарь.";

        $imagePath = base_url('uploads/telegram/craft/standard/backpack_craft.jpg');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
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

    /**
     * ADR-158 / story craft-shortfall-buy-13 — тот же экран нехватки, что видит
     * остальной крафт (`CraftShortageService::describe()`), а не собственный
     * текстовый отказ. `answerCallbackQuery` здесь не дублируем: `handle()` уже
     * снял «часики» в самом начале.
     *
     * @param array<string,array{need:int,have:int,name:string}> $missingResources
     * @param array<string,array{need:int,have:int,name:string}> $missingItems
     * @param array<string,mixed> $recipe
     */
    private function shortageScreen(
        \App\Entities\CharacterEntity $character,
        array $missingResources,
        array $missingItems,
        array $recipe,
        int|string $chatId
    ): ServerResponse {
        $shortage = new \App\Services\Craft\CraftShortageService();
        if ($shortage->isEnabled()) {
            $screen = $shortage->describe($character, $missingResources, $missingItems, 1, $recipe);

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $screen['text'],
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($screen['keyboard']),
            ]);
        }

        return $this->sendError((int) $chatId, 'Не хватает материалов для сборки.');
    }
}
