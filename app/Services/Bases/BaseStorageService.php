<?php

declare(strict_types=1);

namespace App\Services\Bases;

use App\Models\ActionLogModel;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Services\Db\WriteOutcome;
use App\Services\Player\InventorySortService;

/**
 * W2.N6 (ADR-190) — ядро склада базы для обоих клиентов: модель списка, ручная выдача
 * и сдача. Бот (`BaseStorageListAction`, `BaseStorageDepositAction`) и веб
 * (`WebNativeScreenService`, `view=storage`) только рендерят то, что отдаёт сервис.
 *
 * Гейт «на базе» живёт здесь: склад физически на клейм-клетке, руками положить/забрать
 * можно только стоя на ней (`BaseCheckService::checkBaseStatus()['isOnBase']`). Отказ —
 * код `off_base`, ничего не списано; экран клиента не прячет кнопки, а объясняет путь.
 *
 * Двойной тап / повтор формы: каждое списание — условная запись. Выдача идёт через
 * `BaseStorageModel::withdraw()` (берёт «сколько есть» построчно `withdrawRow`), сдача —
 * через `CharacterResourceModel::decrementIfAtLeast()`. Второй одновременный вызов
 * получает `missing`/`empty`, а не второе зачисление.
 *
 * Результат мутации — массив с `code` (`ok` | `off_base` | `bad_resource` | `not_carried` | `missing` |
 * `short` | `empty` | `failed`) и данными для текста.
 */
final class BaseStorageService
{
    public const OK           = 'ok';
    public const OFF_BASE     = 'off_base';
    public const BAD_RESOURCE = 'bad_resource';
    public const NOT_CARRIED  = 'not_carried';
    public const MISSING      = 'missing';
    public const SHORT        = 'short';
    public const EMPTY        = 'empty';
    public const FAILED       = 'failed';

    private BaseStorageModel $storage;
    private CharacterResourceModel $resources;
    private ?BaseCheckService $baseCheck;

    public function __construct(
        ?BaseStorageModel $storage = null,
        ?CharacterResourceModel $resources = null,
        ?BaseCheckService $baseCheck = null,
    ) {
        $this->storage   = $storage ?? new BaseStorageModel();
        $this->resources = $resources ?? new CharacterResourceModel();
        $this->baseCheck = $baseCheck;
    }

    /**
     * На своей ли базе игрок — тот же канонический критерий, что у дронов и построек
     * (`claimed_cells.map_cell_id == cell_number`).
     */
    public function isOnBase(int $characterId): bool
    {
        $this->baseCheck ??= new BaseCheckService();
        $status = $this->baseCheck->checkBaseStatus($characterId);
        return ! empty($status['isOnBase']);
    }

    /**
     * Модель экрана склада: строки (уже отсортированные режимом), итог и флаг «на базе».
     * Строки с нулевым id/пустым именем/нулевым количеством остаются в `rows` (их отсеивает
     * рендерер так же, как раньше), чтобы «…и ещё N видов» у бота считалось как прежде.
     *
     * @return array{rows: list<array<string,mixed>>, total_units: int, on_base: bool, mode: string}
     */
    public function storageModel(int $characterId, string $mode = InventorySortService::MODE_RECENT): array
    {
        $mode = InventorySortService::normalizeMode($mode, InventorySortService::STORAGE_MODES, InventorySortService::MODE_RECENT);
        $rows = InventorySortService::sortRows($this->storageRows($characterId), $mode);

        $total = 0;
        foreach ($rows as $r) {
            $name = is_string($r['name'] ?? null) ? $r['name'] : '';
            $qty  = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
            if ($name !== '' && $qty > 0) {
                $total += $qty;
            }
        }

        return [
            'rows'        => $rows,
            'total_units' => $total,
            'on_base'     => $rows === [] ? false : $this->isOnBase($characterId),
            'mode'        => $mode,
        ];
    }

    /**
     * Добытое в рюкзаке (`character_resources`), от большего к меньшему — то, что можно сдать.
     *
     * @return list<array{resource_id:int, name:string, quantity:int}>
     */
    public function carriedResources(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT cr.id_resources AS resource_id, cr.quantity, r.name
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ? AND cr.quantity > 0
             ORDER BY cr.quantity DESC',
            [$characterId]
        );
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }

        $out = [];
        foreach ($q->getResultArray() as $r) {
            if (! is_array($r)) {
                continue;
            }
            $resId = is_numeric($r['resource_id'] ?? null) ? (int) $r['resource_id'] : 0;
            $qty   = is_numeric($r['quantity'] ?? null) ? (int) $r['quantity'] : 0;
            $name  = is_string($r['name'] ?? null) ? $r['name'] : '';
            if ($resId <= 0 || $qty <= 0 || $name === '') {
                continue;
            }
            $out[] = ['resource_id' => $resId, 'name' => $name, 'quantity' => $qty];
        }

        return $out;
    }

    /**
     * Забрать со склада всё. Каждый вид — через условный `withdraw()`, поэтому второй
     * одновременный вызов не зачислит то, что уже унёс первый.
     *
     * @return array{code:string, units:int}
     */
    public function withdrawAll(int $characterId): array
    {
        if (! $this->isOnBase($characterId)) {
            return ['code' => self::OFF_BASE, 'units' => 0];
        }

        $resourceIds = [];
        foreach ($this->storage->findByCharacter($characterId) as $e) {
            $resId = is_numeric($e['resource_id'] ?? null) ? (int) $e['resource_id'] : 0;
            $qty   = is_numeric($e['quantity'] ?? null) ? (int) $e['quantity'] : 0;
            if ($resId > 0 && $qty > 0) {
                $resourceIds[$resId] = true;
            }
        }
        if ($resourceIds === []) {
            return ['code' => self::EMPTY, 'units' => 0];
        }

        $db = \Config\Database::connect();
        $db->transStart();
        $units = 0;
        try {
            foreach (array_keys($resourceIds) as $resId) {
                $withdrawn = $this->storage->withdraw($characterId, $resId, PHP_INT_MAX);
                if ($withdrawn > 0) {
                    $this->resources->increaseResources($characterId, $resId, $withdrawn);
                    $units += $withdrawn;
                }
            }
            $db->transComplete();
            if ($db->transStatus() === false) {
                return ['code' => self::FAILED, 'units' => 0];
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            return ['code' => self::FAILED, 'units' => 0];
        }

        if ($units <= 0) {
            return ['code' => self::EMPTY, 'units' => 0];
        }

        // Лог «Забрать всё» бот не писал никогда (лента «Куда ушло» его не знает) — не добавляем.
        return ['code' => self::OK, 'units' => $units];
    }

    /**
     * Забрать один вид ресурса целиком.
     *
     * @return array{code:string, withdrawn:int, name:string}
     */
    public function withdrawOne(int $characterId, int $resourceId, ?int $chatId = null): array
    {
        if ($resourceId <= 0) {
            return ['code' => self::BAD_RESOURCE, 'withdrawn' => 0, 'name' => ''];
        }
        if (! $this->isOnBase($characterId)) {
            return ['code' => self::OFF_BASE, 'withdrawn' => 0, 'name' => ''];
        }

        $name = $this->resourceName($resourceId);

        $db = \Config\Database::connect();
        $db->transStart();
        $withdrawn = 0;
        try {
            $withdrawn = $this->storage->withdraw($characterId, $resourceId, PHP_INT_MAX);
            if ($withdrawn > 0) {
                $this->resources->increaseResources($characterId, $resourceId, $withdrawn);
            }
            $db->transComplete();
            if ($db->transStatus() === false) {
                return ['code' => self::FAILED, 'withdrawn' => 0, 'name' => $name];
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            return ['code' => self::FAILED, 'withdrawn' => 0, 'name' => $name];
        }

        if ($withdrawn <= 0) {
            return ['code' => self::MISSING, 'withdrawn' => 0, 'name' => $name];
        }

        $this->log($characterId, $chatId, 'BASE_STORAGE_RETRIEVE_ONE', "res={$name} qty={$withdrawn}");

        return ['code' => self::OK, 'withdrawn' => $withdrawn, 'name' => $name];
    }

    /**
     * Сдать один вид ресурса из рюкзака целиком.
     *
     * @return array{code:string, name:string, quantity:int}
     */
    public function depositOne(int $characterId, int $resourceId, ?int $fromCell = null, ?int $chatId = null): array
    {
        if (! $this->isOnBase($characterId)) {
            return ['code' => self::OFF_BASE, 'name' => '', 'quantity' => 0];
        }
        if ($resourceId <= 0) {
            return ['code' => self::BAD_RESOURCE, 'name' => '', 'quantity' => 0];
        }

        $row = null;
        foreach ($this->carriedResources($characterId) as $r) {
            if ($r['resource_id'] === $resourceId) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            return ['code' => self::NOT_CARRIED, 'name' => '', 'quantity' => 0];
        }

        $db = \Config\Database::connect();
        $db->transStart();
        $outcome = $this->resources->decrementIfAtLeast($characterId, $resourceId, $row['quantity']);
        if ($outcome === WriteOutcome::Applied) {
            $this->storage->deliver($characterId, $resourceId, $row['quantity'], $fromCell);
        }
        // exploit-fix-23 — исход читаем по возврату transComplete(): откат вне ветки
        // WriteOutcome не должен вести в ветку «убрано на склад».
        $committed = $db->transComplete();

        if ($outcome !== WriteOutcome::Applied || ! $committed) {
            return [
                'code'     => $outcome === WriteOutcome::Missing ? self::MISSING : self::SHORT,
                'name'     => $row['name'],
                'quantity' => 0,
            ];
        }

        $this->log($characterId, $chatId, 'BASE_STORAGE_DEPOSIT', "res={$row['name']} qty={$row['quantity']}");

        return ['code' => self::OK, 'name' => $row['name'], 'quantity' => $row['quantity']];
    }

    /**
     * Сдать всё добытое разом.
     *
     * @return array{code:string, units:int, kinds:int, skipped:int}
     */
    public function depositAll(int $characterId, ?int $fromCell = null, ?int $chatId = null): array
    {
        if (! $this->isOnBase($characterId)) {
            return ['code' => self::OFF_BASE, 'units' => 0, 'kinds' => 0, 'skipped' => 0];
        }

        $rows = $this->carriedResources($characterId);
        if ($rows === []) {
            return ['code' => self::EMPTY, 'units' => 0, 'kinds' => 0, 'skipped' => 0];
        }

        $db = \Config\Database::connect();
        $db->transStart();

        $units   = 0;
        $kinds   = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $outcome = $this->resources->decrementIfAtLeast($characterId, $r['resource_id'], $r['quantity']);
            if ($outcome !== WriteOutcome::Applied) {
                $skipped++;
                continue;
            }
            $this->storage->deliver($characterId, $r['resource_id'], $r['quantity'], $fromCell);
            $units += $r['quantity'];
            $kinds++;
        }

        $committed = $db->transComplete();

        if ($kinds === 0 || ! $committed) {
            return ['code' => self::SHORT, 'units' => 0, 'kinds' => 0, 'skipped' => $skipped];
        }

        $this->log($characterId, $chatId, 'BASE_STORAGE_DEPOSIT_ALL', "kinds={$kinds} units={$units} skipped={$skipped}");

        return ['code' => self::OK, 'units' => $units, 'kinds' => $kinds, 'skipped' => $skipped];
    }

    /**
     * Строки склада с именем ресурса (свежие сверху — порядок режима «recent»).
     *
     * @return list<array<string,mixed>>
     */
    private function storageRows(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT bs.id, bs.resource_id, bs.quantity, bs.arrived_from_cell, r.name
             FROM base_storage bs
             INNER JOIN resources r ON r.id = bs.resource_id
             WHERE bs.character_id = ?
             ORDER BY bs.updated_at DESC',
            [$characterId]
        );
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }
        $out = [];
        foreach ($q->getResultArray() as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private function resourceName(int $resourceId): string
    {
        $db  = \Config\Database::connect();
        $q   = $db->query('SELECT name FROM resources WHERE id = ?', [$resourceId]);
        $row = (is_object($q) && method_exists($q, 'getRowArray')) ? $q->getRowArray() : null;
        return (is_array($row) && is_string($row['name'] ?? null)) ? $row['name'] : '';
    }

    private function log(int $characterId, ?int $chatId, string $action, string $description): void
    {
        try {
            (new ActionLogModel())->save([
                'character_id'  => $characterId,
                'chat_id'       => $chatId,
                'action_name'   => $action,
                'action_status' => 'Completed',
                'description'   => mb_substr($description, 0, 500),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[BaseStorageService] action_log insert failed: ' . $e->getMessage());
        }
    }
}
