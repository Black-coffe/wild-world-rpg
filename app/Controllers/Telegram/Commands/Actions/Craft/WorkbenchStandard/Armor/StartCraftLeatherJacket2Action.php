<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchStandard\Armor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsModel;
use App\Models\CraftedItemsLogModel;
use App\Models\TaskModel;
use App\Models\CharacterTaskModel;
use App\Models\ClaimedCellModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class StartCraftLeatherJacket2Action extends BaseAction
{
    protected $characterModel;
    protected $charResourceModel;
    protected $craftedItemsModel;
    protected $craftedItemsLogModel;
    protected $taskModel;
    protected $characterTaskModel;
    protected $claimModel;

    private int $quantity = 1;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel     = new CharacterModel();
        $this->charResourceModel  = new CharacterResourceModel();
        $this->craftedItemsModel  = new CraftedItemsModel();
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->taskModel          = new TaskModel();
        $this->characterTaskModel = new CharacterTaskModel();
        $this->claimModel         = new ClaimedCellModel();

        // Пример разбора callback_data: "startCraftLeatherJacket2_10"
        $data  = $callbackQuery->getData();
        $parts = explode('_', $data);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $this->quantity = (int) $parts[1];
        }
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError($chatId, 'Персонаж не найден.');
        }

        // Проверка переезда (блокировка)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        // Проверка, есть ли база
        $base = $this->claimModel->where('character_id', $character['id'])->first();
        if (!$base) {
            return $this->sendError($chatId, 'У вас нет базы, крафт невозможен.');
        }

        // ----- Повторяем проверки (лучшая практика) -----

        // 1) золото
        $requiredGold = 700;
        $totalGoldNeeded = $requiredGold * $this->quantity;
        if ($character['gold'] < $totalGoldNeeded) {
            return $this->sendError(
                $chatId,
                "Недостаточно золота. Нужно {$totalGoldNeeded}, у вас {$character['gold']}."
            );
        }

        // 2) Крафтовые предметы: 'Складной нож' => 1, 'Ткань' => 8
        $craftedNeed = [
            'Складной нож' => 1,
            'Ткань'        => 8,
        ];

        // Story craft-shortfall-buy-13: вместо отказа на первой нехватке собираем
        // ВСЕ недостающие позиции (компоненты + сырьё) и показываем общий экран
        // нехватки (CraftShortageService), как остальной крафт.
        $componentsRecipe = [];
        $missingItems = [];
        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $totalNeeded = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            if (!$craftedItem) {
                return $this->sendError($chatId, "Не найден предмет «{$itemName}» в crafted_items!");
            }
            $itemEng = is_string($craftedItem['name_eng'] ?? null) ? $craftedItem['name_eng'] : $itemName;
            $componentsRecipe[$itemEng] = $qtyPerOne;
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $haveQty = $logRow ? (int)$logRow['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                $nameRus = is_string($craftedItem['name_rus'] ?? null) ? $craftedItem['name_rus'] : $itemName;
                $missingItems[$itemEng] = ['need' => $totalNeeded, 'have' => $haveQty, 'name' => $nameRus];
            }
        }

        // 3) Сырьевые ресурсы: Кожа животных (x4), Древесина (x2)
        $rawNeeded = [
            ['name' => 'Кожа животных', 'qty' => 4],
            ['name' => 'Древесина',     'qty' => 2],
        ];

        $resourcesRecipe = [];
        $missingResources = [];
        foreach ($rawNeeded as $res) {
            $resourcesRecipe[$res['name']] = $res['qty'];
            $totalNeeded = $res['qty'] * $this->quantity;
            $resourceRow = (new \App\Models\ResourceModel())->getResourceByName($res['name']);
            if (!$resourceRow) {
                return $this->sendError($chatId, "Ресурс «{$res['name']}» не найден в таблице resources!");
            }
            $charRes = $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->first();
            $haveQty = $charRes ? (int)$charRes['quantity'] : 0;
            if ($haveQty < $totalNeeded) {
                $missingResources[$res['name']] = ['need' => $totalNeeded, 'have' => $haveQty, 'name' => $res['name']];
            }
        }

        if ($missingResources !== [] || $missingItems !== []) {
            return $this->shortageScreen($character, $missingResources, $missingItems, [
                'item_name_rus'         => 'Кожаная куртка',
                'info_callback'         => 'armorLeatherJacket',
                'resources'             => $resourcesRecipe,
                'crafted_items'         => $componentsRecipe,
                // fix-07 (остаток критической находки 1 ревью): рецепта нет в
                // Config\CraftRecipes — ключ докупки берём из собственного
                // callback_data старта ('startCraftLeatherJacket2_<qty>'), тот же
                // формат, что и у остальных ~105 рецептов.
                'craft_again_callback' => 'genericCraft_LeatherJacket2_' . $this->quantity,
            ], $chatId);
        }

        // ----- Списываем -----
        // 1) Золото
        $this->characterModel
            ->where('id', $character['id'])
            ->set('gold', 'gold - ' . $totalGoldNeeded, false)
            ->update();

        // 2) Крафтовые предметы
        foreach ($craftedNeed as $itemName => $qtyPerOne) {
            $totalNeeded = $qtyPerOne * $this->quantity;
            $craftedItem = $this->craftedItemsModel->getCraftedItemByName($itemName);
            $logRow = $this->craftedItemsLogModel
                ->where('character_id', $character['id'])
                ->where('crafted_item_id', $craftedItem['id'])
                ->first();
            $newQty = $logRow['quantity'] - $totalNeeded;
            $this->craftedItemsLogModel->update($logRow['id'], ['quantity' => $newQty]);
        }

        // 3) Сырьевые ресурсы
        foreach ($rawNeeded as $res) {
            $totalNeeded = $res['qty'] * $this->quantity;
            $resourceRow = (new \App\Models\ResourceModel())->getResourceByName($res['name']);
            $this->charResourceModel
                ->where('id_characters', $character['id'])
                ->where('id_resources', $resourceRow['id'])
                ->set('quantity', 'quantity - ' . $totalNeeded, false)
                ->update();
        }

        // ----- Создаём запись задачи крафта -----
        $taskRow = $this->taskModel->where('name', 'craftLeatherJacket')->first();
        if (!$taskRow) {
            $newTaskId = $this->taskModel->insert([
                'name'                       => 'craftLeatherJacket',
                'name_rus'                   => 'Крафт Кожаной куртки',
                'description'                => 'Процесс крафта LeatherJacket',
                'min_duration'               => 5,
                'max_duration'               => 15,
                'type'                       => 'craft',
                'difficulty_level'           => 4,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 1,
                'interruptible'              => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $taskRow = ['id' => $newTaskId, 'name' => 'craftLeatherJacket'];
        }

        // === Добавляем проверку, чтобы не начать второй крафт параллельно ===
        $activeTask = $this->characterTaskModel
            ->where('character_id', $character['id'])
            ->where('task_id', $taskRow['id'])
            ->where('status', 'in_work')
            ->first();
        if ($activeTask) {
            return $this->sendError(
                $chatId,
                'У вас уже идёт крафт «Кожаной куртки»! Дождитесь завершения предыдущего крафта.'
            );
        }

        // Допустим 10 минут за 1 шт.
        $timeForOne = 10;
        $totalTime  = $timeForOne * $this->quantity;
        $startTime  = new \DateTime();
        $endTime    = (clone $startTime)->add(new \DateInterval("PT{$totalTime}M"));

        $this->characterTaskModel->insert([
            'character_id'     => $character['id'],
            'telegram_user_id' => $user['id'],
            'task_id'          => $taskRow['id'],
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'item_crafted' => 'LeatherJacket',
                'quantity'     => $this->quantity,
            ]),
        ]);

        // Снимаем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background = $scope->isBackground($taskRow['parallel_execution_allowed'] ?? 1);
        $text = "*Начат крафт {$this->quantity} шт. «Кожаной куртки»!*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "Общее время: ~{$totalTime} мин.\n"
            . "По окончании вы получите результат.\n";

        // Отправляем финальное сообщение
        $imagePath = base_url('uploads/telegram/craft/standard/leather_jacket.jpg');
        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendError($chatId, $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * ADR-158 / story craft-shortfall-buy-13 — тот же экран нехватки, что видит
     * остальной крафт (`CraftShortageService::describe()`), а не собственный
     * текстовый отказ. Ничего не пересчитывает: только собирает вход по уже
     * посчитанным нехваткам. Общая часть (вызов describe() + отправка) —
     * `CraftShortageScreenHelper` (Tier-2, семь копий сведены в story
     * craft-shortage-screen-dedupe-01).
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
        return (new \App\Services\Craft\CraftShortageScreenHelper())->render(
            $character,
            $missingResources,
            $missingItems,
            $this->quantity,
            $recipe,
            $chatId,
            fn () => $this->sendError($chatId, "Недостаточно ресурсов для крафта {$this->quantity} шт."),
            $this->callbackQuery->getId()
        );
    }
}
