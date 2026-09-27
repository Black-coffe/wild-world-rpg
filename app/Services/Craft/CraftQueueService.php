<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Models\BaseStorageModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\CharacterTaskModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\ResourceModel;
use App\Models\TaskModel;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use Config\CraftRecipes;
use Config\Database;
use DateInterval;
use DateTime;

/**
 * W2.N3-02 (ADR-190) — очередь крафта без Telegram: список активных и ожидающих с оценкой времени,
 * отмена ожидающего с возвратом оплаты, продвижение очереди при завершении. Бот (`ShowCraftQueueAction`,
 * `CancelQueuedCraftAction`, `GenericCraftCompletionHandler`) только рендерит исходы, веб (story 03) зовёт
 * те же методы.
 *
 * Атомарность (ADR-181): отмена — условный `DELETE … WHERE status='queued'` первым шагом транзакции,
 * возвраты только при подтверждённом снятии; продвижение — `UPDATE … WHERE status='queued'`, поэтому
 * отмена и продвижение одной строки не срабатывают обе. Возврат идёт туда, откуда списал старт
 * (`task_settings.consumed`, story 01); у строк без разбивки — рецепт × количество в рюкзак.
 * Золото и предметы возвращаются относительной записью (`col = col + ?`).
 *
 * @phpstan-type Active array{charTaskId:int, task_id:int, recipe:string, name:string, qty:int, ends_at:?string, seconds_left:int}
 * @phpstan-type Queued array{charTaskId:int, task_id:int, recipe:string, name:string, qty:int, position:int}
 * @phpstan-type QueuedEta array{charTaskId:int, task_id:int, recipe:string, name:string, qty:int, position:int, minutes_total:int, starts_in_seconds:int}
 * @phpstan-type Cancel array{ok:bool, code:string, message:string, recipe:string, name:string, qty:int}
 * @phpstan-type Promoted array{charTaskId:int, telegram_user_id:int, row:array<array-key,mixed>, recipe:?string, qty:int, ends_at:DateTime}
 */
class CraftQueueService
{
    public const CANCELLED = 'cancelled';
    public const NOT_FOUND = 'not_found';
    public const BAD_DATA  = 'bad_data';
    public const NO_RECIPE = 'no_recipe';
    public const TX_FAILED = 'tx_failed';

    private const NOT_FOUND_TEXT = 'Задача в очереди не найдена или уже активирована.';

    private CharacterTaskModel $tasks;
    private ?CraftDurationService $duration;

    public function __construct(?CharacterTaskModel $tasks = null, ?CraftDurationService $duration = null)
    {
        $this->tasks    = $tasks ?? new CharacterTaskModel();
        $this->duration = $duration;
    }

    /**
     * Крафт-задачи персонажа: активные (in_work, ETA) и ожидающие (queued) — активные первыми по ETA,
     * затем FIFO по id. `position` — порядковый номер в общем списке ожидающих (как нумерует бот).
     *
     * @return array{active: list<Active>, queued: list<Queued>}
     */
    public function rows(int $characterId): array
    {
        $result = $this->db()->table('character_tasks')
            ->select('character_tasks.id AS charTaskId, character_tasks.task_id, character_tasks.end_time, character_tasks.status, character_tasks.task_settings, tasks.name_rus')
            ->join('tasks', 'tasks.id = character_tasks.task_id', 'left')
            ->where('character_tasks.character_id', $characterId)
            ->where('tasks.type', 'craft')
            ->whereIn('character_tasks.status', ['in_work', 'queued'])
            // 'in_work' < 'queued' лексикографически → активные первыми; затем по ETA, затем FIFO id.
            ->orderBy('character_tasks.status', 'ASC')
            ->orderBy('character_tasks.end_time', 'ASC')
            ->orderBy('character_tasks.id', 'ASC')
            ->get();
        $rows = $result === false ? [] : $result->getResultArray();

        $now    = Time::now()->getTimestamp();
        $active = [];
        $queued = [];
        foreach ($rows as $r) {
            $name = is_string($r['name_rus'] ?? null) && $r['name_rus'] !== '' ? $r['name_rus'] : 'Крафт';
            $base = [
                'charTaskId' => $this->int($r['charTaskId'] ?? null),
                'task_id'    => $this->int($r['task_id'] ?? null),
                'recipe'     => $this->recipeKey($r) ?? '',
                'name'       => $name,
                'qty'        => $this->quantity($r),
            ];

            if (($r['status'] ?? '') === 'in_work') {
                $end      = is_string($r['end_time'] ?? null) && $r['end_time'] !== '' ? $r['end_time'] : null;
                $active[] = $base + [
                    'ends_at'      => $end,
                    'seconds_left' => $end !== null ? max(0, (int) strtotime($end) - $now) : 0,
                ];
            } else {
                $queued[] = $base + ['position' => count($queued) + 1];
            }
        }

        return ['active' => $active, 'queued' => $queued];
    }

    /**
     * {@see rows()} + оценка «≈» для ожидающих: `starts_in_seconds` = остаток активного крафта того же
     * рецепта + `minutesForOne × qty` каждого ожидающего того же рецепта впереди; `minutes_total` —
     * длительность самой строки. Длительность — {@see CraftDurationService} по текущим статам.
     *
     * @return array{active: list<Active>, queued: list<QueuedEta>}
     */
    public function forCharacter(int $characterId): array
    {
        $rows      = $this->rows($characterId);
        $character = $this->queued($rows) ? $this->character($characterId) : null;

        $clock = [];
        foreach ($rows['active'] as $a) {
            $clock[$a['task_id']] = max($clock[$a['task_id']] ?? 0, $a['seconds_left']);
        }

        $minutesOne = [];
        $queued     = [];
        foreach ($rows['queued'] as $q) {
            $key = $q['task_id'] . '|' . $q['recipe'];
            $minutesOne[$key] ??= $this->minutesForOne($character, $q['task_id'], $q['recipe']);
            $total    = $minutesOne[$key] * $q['qty'];
            $startsIn = $clock[$q['task_id']] ?? 0;
            $queued[] = $q + ['minutes_total' => $total, 'starts_in_seconds' => $startsIn];
            $clock[$q['task_id']] = $startsIn + $total * 60;
        }

        return ['active' => $rows['active'], 'queued' => $queued];
    }

    /**
     * Отмена ожидающего крафта с возвратом оплаты. Решение о возврате принимает условный
     * `DELETE … WHERE status='queued'` (affectedRows), а не прочитанная до транзакции строка.
     *
     * @return Cancel
     */
    public function cancel(int $characterId, int $charTaskId): array
    {
        $fail = static fn (string $code, string $message, string $recipe = '', string $name = '', int $qty = 0): array => [
            'ok' => false, 'code' => $code, 'message' => $message, 'recipe' => $recipe, 'name' => $name, 'qty' => $qty,
        ];

        $task = $this->findQueued($characterId, $charTaskId);
        if ($task === null) {
            return $fail(self::NOT_FOUND, self::NOT_FOUND_TEXT);
        }

        $settings  = $this->settings($task);
        $recipeKey = $settings['recipe'] ?? null;
        $quantity  = $this->quantity($task);
        if (!is_string($recipeKey)) {
            return $fail(self::BAD_DATA, 'Поврежденные данные задачи.');
        }

        /** @var CraftRecipes $cfg */
        $cfg    = config('CraftRecipes');
        $recipe = $cfg->get($recipeKey);
        if ($recipe === null) {
            return $fail(self::NO_RECIPE, "Неизвестный рецепт: {$recipeKey}");
        }
        $name = is_string($recipe['item_name_rus'] ?? null) ? $recipe['item_name_rus'] : $recipeKey;

        $db = $this->db();
        $db->transBegin();
        try {
            $prefixed = $db->prefixTable('character_tasks');
            $db->query(
                "DELETE FROM {$prefixed} WHERE id = ? AND character_id = ? AND status = ?",
                [$charTaskId, $characterId, 'queued']
            );
            $rowRemoved = $db->affectedRows() >= 1;

            if ($rowRemoved) {
                $this->refund($db, $characterId, $recipe, $settings, $quantity);
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                log_message('error', "[CraftQueueService] отмена: транзакция упала task_id={$charTaskId} char_id={$characterId}");

                return $fail(self::TX_FAILED, 'Ошибка при отмене. Попробуйте ещё раз.');
            }
            $db->transCommit();
        } catch (DatabaseException $e) {
            $db->transRollback();
            log_message('error', "[CraftQueueService] отмена: транзакция упала task_id={$charTaskId} char_id={$characterId}: " . $e->getMessage());

            return $fail(self::TX_FAILED, 'Ошибка при отмене. Попробуйте ещё раз.');
        }

        if (!$rowRemoved) {
            // Строку между чтением и записью забрал воркер или параллельная отмена — без возврата.
            return $fail(self::NOT_FOUND, self::NOT_FOUND_TEXT);
        }

        return ['ok' => true, 'code' => self::CANCELLED, 'message' => '', 'recipe' => $recipeKey, 'name' => $name, 'qty' => $quantity];
    }

    /**
     * Продвижение очереди рецепта после завершения: старейшая `queued`-строка того же персонажа и
     * `task_id` → `in_work` с длительностью {@see CraftDurationService} × qty. Запись условна
     * (`WHERE status='queued'`): строку, снятую отменой или уже продвинутую другим воркером, не
     * запускает — берёт следующую.
     *
     * @return Promoted|null
     */
    public function promoteNext(int $characterId, int $taskId): ?array
    {
        $afterId   = 0;
        $taskRow   = null;
        $character = null;
        while (($next = $this->nextQueued($characterId, $taskId, $afterId)) !== null) {
            $taskRow ??= (new TaskModel())->find($taskId);
            if (!is_array($taskRow)) {
                log_message('error', "[CraftQueueService] dequeue: tasks row {$taskId} не знайдено");

                return null;
            }
            $character ??= $this->character($characterId);
            if ($character === null) {
                log_message('error', "[CraftQueueService] dequeue: character {$characterId} не знайдено");

                return null;
            }

            $quantity  = $this->quantity($next);
            $recipeKey = $this->recipeKey($next);
            $recipeRow = [];
            if ($recipeKey !== null) {
                /** @var CraftRecipes $cfgRecipes */
                $cfgRecipes = config('CraftRecipes');
                $recipeRow  = $cfgRecipes->get($recipeKey) ?? [];
            }
            $total = $this->durationService()->minutesForOne($character, $taskRow, $recipeRow) * $quantity;
            $start = new DateTime();
            $end   = (clone $start)->add(new DateInterval('PT' . $total . 'M'));

            $nextId = $this->int($next['id'] ?? null);
            if ($nextId < 1) {
                log_message('error', '[CraftQueueService] dequeue: у queued-задачи нет id');

                return null;
            }

            $db = $this->db();
            $db->query(
                'UPDATE ' . $db->prefixTable('character_tasks') . ' SET status = ?, start_time = ?, end_time = ?, updated_at = ? WHERE id = ? AND status = ?',
                ['in_work', $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $nextId, 'queued']
            );
            if ($db->affectedRows() >= 1) {
                return [
                    'charTaskId'       => $nextId,
                    'telegram_user_id' => $this->int($next['telegram_user_id'] ?? null),
                    'row'              => $next,
                    'recipe'           => $recipeKey,
                    'qty'              => $quantity,
                    'ends_at'          => $end,
                ];
            }
            $afterId = $nextId;
        }

        return null;
    }

    /**
     * Строка очереди персонажа, пока она `queued` (снимок до транзакции; решает условный DELETE).
     *
     * @return array<array-key,mixed>|null
     */
    protected function findQueued(int $characterId, int $charTaskId): ?array
    {
        $row = $this->tasks
            ->where('id', $charTaskId)
            ->where('character_id', $characterId)
            ->where('status', 'queued')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Старейшая (FIFO по id) `queued`-строка рецепта после `$afterId` (снимок; решает условный UPDATE).
     *
     * @return array<array-key,mixed>|null
     */
    protected function nextQueued(int $characterId, int $taskId, int $afterId): ?array
    {
        $row = $this->tasks
            ->where('character_id', $characterId)
            ->where('task_id', $taskId)
            ->where('status', 'queued')
            ->where('id >', $afterId)
            ->orderBy('id', 'ASC')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param BaseConnection<object, object> $db
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $settings
     */
    private function refund(BaseConnection $db, int $characterId, array $recipe, array $settings, int $quantity): void
    {
        $writer   = new ConditionalWriteService($db);
        $consumed = is_array($settings['consumed'] ?? null) ? $settings['consumed'] : null;

        if ($consumed !== null) {
            $resources = [];
            foreach (is_array($consumed['resources'] ?? null) ? $consumed['resources'] : [] as $name => $from) {
                if (is_array($from)) {
                    $resources[(string) $name] = ['backpack' => $this->int($from['backpack'] ?? null), 'storage' => $this->int($from['storage'] ?? null)];
                }
            }
            $items = $this->intMap($consumed['crafted_items'] ?? []);
            $gold  = $this->int($consumed['gold'] ?? null);
        } else {
            // Строка до story 01: разбивки нет — рецепт × количество в рюкзак (прежнее поведение).
            $resources = [];
            foreach ($this->intMap($recipe['resources'] ?? []) as $name => $perOne) {
                $resources[$name] = ['backpack' => $perOne * $quantity, 'storage' => 0];
            }
            $items = [];
            foreach ($this->intMap($recipe['crafted_items'] ?? []) as $name => $perOne) {
                $items[$name] = $perOne * $quantity;
            }
            $gold = $this->int($recipe['gold_required'] ?? null) * $quantity;
        }

        foreach ($resources as $name => $from) {
            $resource   = (new ResourceModel())->where('name', $name)->first();
            $resourceId = $resource !== null ? $this->int($resource->id ?? null) : 0;
            if ($resourceId < 1) {
                continue;
            }
            if ($from['backpack'] > 0) {
                $this->addToBackpack($db, $writer, $characterId, $resourceId, $from['backpack']);
            }
            if ($from['storage'] > 0) {
                $this->addToStorage($db, $writer, $characterId, $resourceId, $from['storage']);
            }
        }

        foreach ($items as $itemEn => $qty) {
            if ($qty > 0) {
                $this->addCraftedItem($db, $writer, $characterId, $itemEn, $qty);
            }
        }

        if ($gold > 0) {
            $writer->increment('characters', ['id' => $characterId], 'gold', $gold);
        }
    }

    /** @param BaseConnection<object, object> $db */
    private function addToBackpack(BaseConnection $db, ConditionalWriteService $writer, int $characterId, int $resourceId, int $qty): void
    {
        $rowId = $this->firstId($db, 'character_resources', ['id_characters' => $characterId, 'id_resources' => $resourceId]);
        if ($rowId > 0 && $writer->increment('character_resources', ['id' => $rowId], 'quantity', $qty) === WriteOutcome::Applied) {
            return;
        }
        (new CharacterResourceModel())->insert(['id_characters' => $characterId, 'id_resources' => $resourceId, 'quantity' => $qty]);
    }

    /** @param BaseConnection<object, object> $db */
    private function addToStorage(BaseConnection $db, ConditionalWriteService $writer, int $characterId, int $resourceId, int $qty): void
    {
        $rowId = $this->firstId($db, 'base_storage', ['character_id' => $characterId, 'resource_id' => $resourceId]);
        if ($rowId > 0 && $writer->increment('base_storage', ['id' => $rowId], 'quantity', $qty) === WriteOutcome::Applied) {
            return;
        }
        (new BaseStorageModel())->deliver($characterId, $resourceId, $qty);
    }

    /** @param BaseConnection<object, object> $db */
    private function addCraftedItem(BaseConnection $db, ConditionalWriteService $writer, int $characterId, string $itemEn, int $qty): void
    {
        $item = (new CraftedItemsModel())->getRowByName($itemEn);
        if (!is_array($item)) {
            return;
        }
        $itemId = $this->int($item['id'] ?? null);
        $rowId  = $this->firstId($db, 'crafted_items_log', ['character_id' => $characterId, 'crafted_item_id' => $itemId]);
        if ($rowId > 0 && $writer->increment('crafted_items_log', ['id' => $rowId], 'quantity', $qty) === WriteOutcome::Applied) {
            return;
        }
        (new CraftedItemsLogModel())->insert([
            'character_id'      => $characterId,
            'task_id'           => null,
            'crafted_item_id'   => $itemId,
            'type'              => $this->scalar($item['type'] ?? null),
            'direction_craft'   => $this->scalar($item['direction_craft'] ?? null),
            'crafting_location' => $this->scalar($item['crafting_location'] ?? null),
            'durability_count'  => $this->scalar($item['durability_count'] ?? null),
            'durability_time'   => null,
            'quantity'          => $qty,
        ]);
    }

    /**
     * @param BaseConnection<object, object> $db
     * @param array<string,int> $where
     */
    private function firstId(BaseConnection $db, string $table, array $where): int
    {
        $result = $db->table($table)->select('id')->where($where)->orderBy('id', 'ASC')->limit(1)->get();
        $row    = $result === false ? null : $result->getRowArray();

        return is_array($row) ? $this->int($row['id'] ?? null) : 0;
    }

    private function minutesForOne(?CharacterEntity $character, int $taskId, string $recipeKey): int
    {
        if ($character === null) {
            return 0;
        }
        $taskRow = (new TaskModel())->find($taskId);
        if (!is_array($taskRow)) {
            return 0;
        }
        /** @var CraftRecipes $cfg */
        $cfg = config('CraftRecipes');

        return $this->durationService()->minutesForOne($character, $taskRow, $recipeKey !== '' ? ($cfg->get($recipeKey) ?? []) : []);
    }

    /** @param array{active: list<Active>, queued: list<Queued>} $rows */
    private function queued(array $rows): bool
    {
        return $rows['queued'] !== [];
    }

    private function character(int $characterId): ?CharacterEntity
    {
        $character = (new CharacterModel())->find($characterId);

        return $character instanceof CharacterEntity ? $character : null;
    }

    private function durationService(): CraftDurationService
    {
        return $this->duration ??= new CraftDurationService();
    }

    /** @return BaseConnection<object, object> */
    private function db(): BaseConnection
    {
        return Database::connect();
    }

    /**
     * @param array<array-key,mixed> $row
     * @return array<array-key,mixed>
     */
    private function settings(array $row): array
    {
        $decoded = json_decode(is_string($row['task_settings'] ?? null) ? $row['task_settings'] : '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ключ рецепта: `recipe`, у старых стартов — `item_crafted`.
     *
     * @param array<array-key,mixed> $row
     */
    private function recipeKey(array $row): ?string
    {
        $s   = $this->settings($row);
        $key = $s['recipe'] ?? $s['item_crafted'] ?? null;

        return is_string($key) ? $key : null;
    }

    /** @param array<array-key,mixed> $row */
    private function quantity(array $row): int
    {
        $s = $this->settings($row);

        return isset($s['quantity']) && is_numeric($s['quantity']) ? max(1, (int) $s['quantity']) : 1;
    }

    /** @return array<string,int> */
    private function intMap(mixed $raw): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $out[(string) $k] = $this->int($v);
            }
        }

        return $out;
    }

    private function scalar(mixed $v): bool|float|int|string|null
    {
        return is_scalar($v) ? $v : null;
    }

    private function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
