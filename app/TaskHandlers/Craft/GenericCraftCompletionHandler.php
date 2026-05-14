<?php

declare(strict_types=1);

namespace App\TaskHandlers\Craft;

use App\Attributes\HandlerKey;
use App\Models\CharacterModel;
use App\Models\CharactersWeaponsModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TaskModel;
use App\Models\TelegramUserModel;
use App\Models\WeaponModel;
use App\TaskHandlers\BaseTaskHandler;
use Config\CraftRecipes;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * F2.2 (PoC) — generic-handler завершения любого крафта.
 *
 * Заменяет 42 файла `app/TaskHandlers/Craft/CraftCompletion*Handler.php`
 * (~7560 LOC дубля). Какой именно предмет создан — берётся из
 * `task_settings.recipe` (string) и резолвится через `Config\CraftRecipes`.
 *
 * **Не подключён к Worker** в этом коммите. План:
 *   1. Создать новый таск-row в БД для тестирования (`tasks.name = 'craftBandage'`).
 *   2. В `Worker::getHandlerClassName()` (или в TaskDispatcher F2.4)
 *      сделать routing: если `tasks.name = 'craft<X>'` И есть рецепт
 *      `<X>` в `Config\CraftRecipes` → использовать
 *      `GenericCraftCompletionHandler` вместо
 *      `CraftCompletion<X>Handler`.
 *   3. Прокатать на одном крафте (Bandage), сравнить логи.
 *   4. Включить остальные 41 — добавлением в `CraftRecipes::$recipes`,
 *      без правок Handler-кода.
 *
 * Поведение 1:1 с `CraftCompletionBandageHandler.php`:
 *   - mark `character_tasks.status = 'completed'`,
 *   - find `crafted_items` by `name_eng`,
 *   - extract `quantity` from `task_settings`,
 *   - update or insert `crafted_items_log`,
 *   - bump character agility/intellect через
 *     `CharacterModel::updateAgilityAndIntellect()`,
 *   - notify user (photo with caption + "craft again"/"inventory" keyboard).
 *
 * НЕ применяется F0.3 atomic-claim здесь — Worker уже делает это в
 * `handleTask` обвязке. Двойной flip status='completed' — no-op.
 */
#[HandlerKey(
    key: 'generic_craft',
    displayName: 'Универсальное завершение крафта',
    description: 'Завершает любой craft* task (рецепт читается из task_settings.recipe в Config\\CraftRecipes). Покрывает ~34 крафта: medical/components/tools/weapons/teleport/robots.',
)]
class GenericCraftCompletionHandler extends BaseTaskHandler
{
    private CharacterModel          $characterModel;
    private CharacterTaskModel      $characterTaskModel;
    private CraftedItemsModel       $craftedItemsModel;
    private CraftedItemsLogModel    $craftedItemsLogModel;
    private TelegramUserModel       $telegramUserModel;
    // F3.B9: weapons output_type — отдельная пара моделей.
    private WeaponModel             $weaponModel;
    private CharactersWeaponsModel  $charactersWeaponsModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->characterTaskModel     = new CharacterTaskModel();
        $this->craftedItemsModel      = new CraftedItemsModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->telegramUserModel      = new TelegramUserModel();
        $this->weaponModel            = new WeaponModel();
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
    }

    public function handle(array $task = []): void
    {
        // 1. recipe key из task_settings
        $recipeKey = $this->extractRecipeKey($task);
        if ($recipeKey === null) {
            log_message('error', "[GenericCraftCompletion] task_settings.recipe не задан, task_id=" . ($task['id'] ?? '?'));
            return;
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            log_message('error', "[GenericCraftCompletion] нет рецепта '{$recipeKey}' в Config\\CraftRecipes");
            return;
        }

        // 2. Mark task as completed (Worker мог уже это сделать в F0.3 atomic claim)
        if (!empty($task['id'])) {
            $this->characterTaskModel->update($task['id'], ['status' => 'completed']);
        }

        // 3. quantity
        $quantityToAdd = $this->extractQuantity($task);

        // F3.B9: dispatch на output_type. Default = 'crafted_item' (B5-B8).
        $outputType = $recipe['output_type'] ?? 'crafted_item';
        if ($outputType === 'weapon') {
            $this->handleWeaponOutput($task, $recipe, $quantityToAdd, $recipeKey);
            return;
        }

        // ========== Default flow: crafted_item (B5-B8) ==========

        // 4. Lookup item в БД по name_eng
        $craftedItem = $this->craftedItemsModel->where('name_eng', $recipe['item_name_eng'])->first();
        if (!$craftedItem) {
            log_message('error', "[GenericCraftCompletion] crafted_item '{$recipe['item_name_eng']}' не найден в БД");
            return;
        }

        // 5. update / insert crafted_items_log
        $existingLog = $this->craftedItemsLogModel->where([
            'character_id'    => $task['character_id'],
            'crafted_item_id' => $craftedItem['id'],
        ])->first();

        if ($existingLog) {
            $newQty = $existingLog['quantity'] + $quantityToAdd;
            $this->craftedItemsLogModel->update($existingLog['id'], ['quantity' => $newQty]);
        } else {
            $this->craftedItemsLogModel->insert([
                'character_id'      => $task['character_id'],
                'task_id'           => $task['task_id'] ?? null,
                'crafted_item_id'   => $craftedItem['id'],
                'type'              => $craftedItem['type'],
                'direction_craft'   => $craftedItem['direction_craft'],
                'crafting_location' => $craftedItem['crafting_location'],
                'durability_count'  => $craftedItem['durability_count'],
                'durability_time'   => null,
                'quantity'          => $quantityToAdd,
            ]);
        }

        // 6. bump статов персонажа
        // F1.4.4-B 15th: $task['character_id'] приходит string из raw row → (int) cast.
        if (method_exists($this->characterModel, 'updateAgilityAndIntellect')) {
            $this->characterModel->updateAgilityAndIntellect(
                (int) $task['character_id'],
                (float) $recipe['agility_bonus'],
                (float) $recipe['intellect_bonus']
            );
        }

        // 7. notify
        $this->notifyUser((int) $task['telegram_user_id'], $craftedItem, (int) $task['character_id'], $quantityToAdd, $recipe, $recipeKey);

        // v0.51.129 (community idea #1): dequeue next queued task для same recipe.
        $this->activateNextQueuedTask((int) $task['character_id'], (int) $task['task_id']);
    }

    /**
     * v0.51.129: знайти oldest queued task (FIFO) для same character + same
     * task_id → activate (status='in_work', start_time=now, end_time=now+dur×qty).
     *
     * Worker.php picks status='in_work' AND end_time<now — наступний tick
     * (≤1 хв) обробить активований task через цей же handler.
     */
    private function activateNextQueuedTask(int $characterId, int $taskId): void
    {
        $next = $this->characterTaskModel
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'queued')
            ->orderBy('id', 'ASC')  // FIFO via auto-increment (created_at ties resolved)
            ->first();

        if ($next === null) {
            return;
        }

        // Дюрація based on character (поточні stats) + qty з task_settings.
        $taskRow = (new TaskModel())->find($taskId);
        if ($taskRow === null) {
            log_message('error', "[GenericCraftCompletion] dequeue: tasks row {$taskId} не знайдено");
            return;
        }

        $character = $this->characterModel->find($characterId);
        if ($character === null) {
            log_message('error', "[GenericCraftCompletion] dequeue: character {$characterId} не знайдено");
            return;
        }

        $quantity      = $this->extractQuantity($next);
        $durationOne   = $this->calculateCraftingDuration($character, $taskRow);
        $totalDuration = $durationOne * $quantity;

        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $totalDuration . 'M'));

        $this->characterTaskModel->update($next['id'], [
            'status'     => 'in_work',
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time'   => $endTime->format('Y-m-d H:i:s'),
        ]);

        // notify користувачу про активацію черги
        $this->notifyQueuedActivated((int) $next['telegram_user_id'], $next, $quantity, $endTime);
    }

    /**
     * Та сама формула, що в `GenericCraftActionStart::calculateCraftingDuration`.
     * Дублюємо для незалежності від action layer.
     *
     * @param array<string,mixed>|\App\Entities\CharacterEntity $character
     * @param array<string,mixed> $taskRow
     */
    private function calculateCraftingDuration(array|\App\Entities\CharacterEntity $character, array $taskRow): int
    {
        $expFactor = 0.3;
        $agiFactor = 0.3;
        $intFactor = 0.4;

        $score    = ((float) $character['experience'] * $expFactor)
                  + ((float) $character['agility']    * $agiFactor)
                  + ((float) $character['intellect']  * $intFactor);
        $maxScore = 1000 * ($expFactor + $agiFactor + $intFactor);
        $norm     = $maxScore > 0 ? $score / $maxScore : 0;

        $minD = (int) $taskRow['min_duration'];
        $maxD = (int) $taskRow['max_duration'];

        $adjusted = $minD + ($maxD - $minD) * (1 - $norm);
        return max($minD, min($maxD, (int) round($adjusted)));
    }

    /**
     * @param array<string, mixed> $task
     */
    private function notifyQueuedActivated(int $telegramUserId, array $task, int $quantity, DateTime $endTime): void
    {
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            return;
        }
        $telegramId = (int) $userRow['telegram_id'];

        $recipeKey = $this->extractRecipeKey($task);
        if ($recipeKey === null) {
            return;
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            return;
        }

        $endTimeStr = $endTime->format('H:i');
        $text = "▶️ *Очередь активирована!*\n\n"
            . "Запущен крафт: {$recipe['start_caption_name']} x{$quantity} шт.\n\n"
            . "Завершится в *{$endTimeStr}*.";

        $this->safeSendMessage($telegramId, $text, ['parse_mode' => 'Markdown']);
    }

    /**
     * F3.B9 — completion path для weapons. Записывает в `characters_weapons`
     * (вместо `crafted_items_log`), обновляет силу/ловкость через
     * `updateStrengthAndAgility`, уведомляет игрока.
     */
    private function handleWeaponOutput(array $task, array $recipe, int $quantityToAdd, string $recipeKey): void
    {
        $weaponNameEn = $recipe['weapon_name_en'] ?? null;
        if ($weaponNameEn === null) {
            log_message('error', "[GenericCraftCompletion] recipe '{$recipe['item_name_eng']}' output_type=weapon, но нет weapon_name_en");
            return;
        }

        $weapon = $this->weaponModel->where('name_en', $weaponNameEn)->first();
        if (!$weapon) {
            log_message('error', "[GenericCraftCompletion] weapon '{$weaponNameEn}' не найден в БД");
            return;
        }

        // Add or increment quantity in characters_weapons
        $row = $this->charactersWeaponsModel
            ->where('character_id', $task['character_id'])
            ->where('weapon_id', $weapon['id'])
            ->first();

        if ($row) {
            $newQty = (int) $row['quantity'] + $quantityToAdd;
            $this->charactersWeaponsModel->update($row['id'], ['quantity' => $newQty]);
        } else {
            $this->charactersWeaponsModel->insert([
                'character_id'       => $task['character_id'],
                'weapon_id'          => $weapon['id'],
                'quantity'           => $quantityToAdd,
                'current_durability' => 100,
                'equipped'           => 0,
                'slot'               => $recipe['weapon_slot'] ?? 'hand',
            ]);
        }

        // Stat bump: для weapons → updateStrengthAndAgility (НЕ updateAgilityAndIntellect).
        // F1.4.4-B 15th: $task['character_id'] приходит string из raw row → (int) cast.
        if (method_exists($this->characterModel, 'updateStrengthAndAgility')) {
            $this->characterModel->updateStrengthAndAgility(
                (int) $task['character_id'],
                (float) ($recipe['strength_bonus'] ?? 0),
                (float) ($recipe['agility_bonus']  ?? 0)
            );
        }

        // notify — переиспользуем notifyUser, передавая weapon-row (используем
        // weapons.name как name_rus + weapon_id для recount итога).
        $weaponAsItem = [
            'id'       => (int) $weapon['id'],
            'name_rus' => $weapon['name'] ?? $recipe['item_name_rus'],
            '__weapon' => true,
        ];
        $this->notifyUser((int) $task['telegram_user_id'], $weaponAsItem, (int) $task['character_id'], $quantityToAdd, $recipe, $recipeKey);

        // v0.51.129: dequeue next queued task для same recipe (для weapon path теж).
        $this->activateNextQueuedTask((int) $task['character_id'], (int) $task['task_id']);
    }

    private function extractRecipeKey(array $task): ?string
    {
        if (empty($task['task_settings'])) {
            return null;
        }
        $decoded = json_decode((string) $task['task_settings'], true);
        if (!is_array($decoded)) {
            return null;
        }
        // F2.2 fallback: legacy start-actions writes 'item_crafted', new ones
        // write 'recipe'. Pre-cutover тасков уже в БД может быть ~12-24h —
        // обрабатываем оба формата.
        $key = $decoded['recipe'] ?? $decoded['item_crafted'] ?? null;
        return is_string($key) ? $key : null;
    }

    private function extractQuantity(array $task): int
    {
        if (empty($task['task_settings'])) {
            return 1;
        }
        $decoded = json_decode((string) $task['task_settings'], true);
        if (is_array($decoded) && isset($decoded['quantity']) && is_numeric($decoded['quantity'])) {
            return max(1, (int) $decoded['quantity']);
        }
        return 1;
    }

    /**
     * @param array<string,mixed> $craftedItem
     * @param array<string,mixed> $recipe
     */
    private function notifyUser(int $telegramUserId, array $craftedItem, int $characterId, int $quantityAdded, array $recipe, string $recipeKey): void
    {
        $userRow = $this->telegramUserModel->where('id', $telegramUserId)->first();
        if (!$userRow || empty($userRow['telegram_id'])) {
            log_message('error', "[GenericCraftCompletion] нет telegram_id для user_id={$telegramUserId}");
            return;
        }
        $telegramId = $userRow['telegram_id'];

        // F3.B9: для weapons итого считаем из characters_weapons,
        // для crafted_item — из crafted_items_log (default).
        if (!empty($craftedItem['__weapon'])) {
            $row = $this->charactersWeaponsModel->where([
                'character_id' => $characterId,
                'weapon_id'    => $craftedItem['id'],
            ])->first();
            $totalNow = $row ? (int) $row['quantity'] : 0;
        } else {
            $updatedLog = $this->craftedItemsLogModel->where([
                'character_id'    => $characterId,
                'crafted_item_id' => $craftedItem['id'],
            ])->first();
            $totalNow = $updatedLog ? (int) $updatedLog['quantity'] : 0;
        }

        $itemNameRus = $craftedItem['name_rus'] ?? $recipe['item_name_rus'];

        $text = "📌 *Крафт завершён!*\n\n"
            . "Ты создал: {$recipe['icon_emoji']} *{$itemNameRus}* x{$quantityAdded} шт.\n\n"
            . "Теперь у тебя *{$totalNow} шт.* в инвентаре.\n\n"
            . "Зона применения: *{$recipe['zone_name']}* {$recipe['zone_emoji']}";

        // Идея #4 (VEGA, 24.01.2025): «Крафтить ещё» сохраняет qty последнего крафта,
        // а не сбрасывает в 1 (как раньше через recipe.craft_again_callback).
        $craftAgainCallback = 'genericCraft_' . $recipeKey . '_' . $quantityAdded;

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Крафтить еще', 'callback_data' => $craftAgainCallback],
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                ],
            ],
        ];

        // F2.2: encodeFile делает fopen() — нужен ЛОКАЛЬНЫЙ путь, не URL.
        // base_url() при пустом app.baseURL (cron) валится на localhost:8080.
        $imagePath = FCPATH . (string) $recipe['image_completed'];

        // Безопасная отправка (BaseTaskHandler::safeSendPhoto ловит TelegramException).
        $this->safeSendPhoto($telegramId, $imagePath, $text, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
