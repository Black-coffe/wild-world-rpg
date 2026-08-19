<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Services\Bases\BaseCheckService;
use App\Services\GameSettings\GameSettingsService;
use RuntimeException;

/**
 * ADR-171 — единая точка правды «сколько ресурса X доступно у персонажа» и
 * «списать X штук» поверх рюкзака (`character_resources`) и склада базы
 * (`base_storage`).
 *
 * Пока игрок стоит на своей базе (`BaseCheckService::checkBaseStatus()['isOnBase']`,
 * единственный канонический критерий в проекте) и killswitch `storage.pool_enabled`
 * включён — рюкзак и склад считаются одним пулом: чтение суммирует оба, списание
 * тратит сначала рюкзак и только остаток — со склада. Вне базы или при выключенном
 * killswitch'е виден только рюкзак — то есть сегодняшнее поведение до этой стори.
 *
 * Транзакции — забота вызывающего. `consume()` НЕ открывает свою транзакцию: крафт
 * и ремонт уже работают внутри своей `$db->transStart()`
 * (см. `docs/specs/storage-craft-insurance/plan.md` §Contracts).
 *
 * Story storage-craft-insurance-01 — tracer. Кто пользуется: волна 2 (крафт,
 * ремонт/апгрейд построек, теплица, выдача со склада) — story 03..06.
 */
class ResourcePoolService
{
    private const KILLSWITCH_KEY = 'storage.pool_enabled';

    protected CharacterResourceModel $characterResourceModel;
    protected BaseStorageModel $baseStorageModel;
    protected ResourceModel $resourceModel;
    protected BaseCheckService $baseCheckService;
    protected GameSettingsService $gameSettings;

    public function __construct(
        ?CharacterResourceModel $characterResourceModel = null,
        ?BaseStorageModel $baseStorageModel = null,
        ?ResourceModel $resourceModel = null,
        ?BaseCheckService $baseCheckService = null,
        ?GameSettingsService $gameSettings = null
    ) {
        $this->characterResourceModel = $characterResourceModel ?? new CharacterResourceModel();
        $this->baseStorageModel       = $baseStorageModel ?? new BaseStorageModel();
        $this->resourceModel          = $resourceModel ?? new ResourceModel();
        $this->baseCheckService       = $baseCheckService ?? new BaseCheckService();
        $this->gameSettings           = $gameSettings ?? new GameSettingsService();
    }

    /** Доступно всего: рюкзак + склад базы, если игрок на базе и killswitch включён. */
    public function available(int $characterId, int $resourceId): int
    {
        $b = $this->breakdown($characterId, $resourceId);

        return $b['backpack'] + ($b['pooled'] ? $b['storage'] : 0);
    }

    /** То же по имени ресурса — крафт оперирует именами из рецепта, не id. */
    public function availableByName(int $characterId, string $resourceName): int
    {
        $resourceId = $this->resolveResourceId($resourceName);
        if ($resourceId === null) {
            return 0;
        }

        return $this->available($characterId, $resourceId);
    }

    /**
     * Разбивка для текста игроку. `storage` — фактическое количество на складе
     * (даже если сейчас не считается частью пула — игроку полезно знать, что
     * оно там есть). `pooled` — считается ли оно доступным прямо сейчас.
     *
     * @return array{backpack:int, storage:int, pooled:bool}
     */
    public function breakdown(int $characterId, int $resourceId): array
    {
        return [
            'backpack' => $this->backpackQuantity($characterId, $resourceId),
            'storage'  => $this->storageQuantity($characterId, $resourceId),
            'pooled'   => $this->isPooled($characterId),
        ];
    }

    /**
     * Списать qty: сначала рюкзак, остаток — со склада. При нехватке бросает
     * исключение и не списывает ничего — проверка идёт до любой мутации.
     *
     * Story storage-craft-insurance-08: снапшот `breakdown()` в начале метода
     * может устареть между чтением и записью (двойной тап, вебхук рядом с
     * крон-Worker'ом). `withdrawBackpack()`/`withdrawStorage()` списывают
     * атомарно и честно возвращают, сколько реально ушло — метод СВЕРЯЕТ
     * это с затребованным по каждому источнику и при расхождении откатывает
     * уже списанное и отказывает: недоплаченный крафт/ремонт не стартует.
     *
     * @return array{backpack:int, storage:int} сколько откуда РЕАЛЬНО ушло
     * @throws RuntimeException если доступного меньше qty, либо если фактическое
     *                          списание разошлось с затребованным (гонка)
     */
    public function consume(int $characterId, int $resourceId, int $qty): array
    {
        if ($qty < 1) {
            return ['backpack' => 0, 'storage' => 0];
        }

        $b            = $this->breakdown($characterId, $resourceId);
        $backpackHave = $b['backpack'];
        $storageHave  = $b['pooled'] ? $b['storage'] : 0;

        if ($backpackHave + $storageHave < $qty) {
            throw new RuntimeException(
                "Недостаточно ресурса #{$resourceId}: нужно {$qty}, доступно " . ($backpackHave + $storageHave)
            );
        }

        $fromBackpack = min($backpackHave, $qty);
        $fromStorage  = $qty - $fromBackpack;

        $backpackTaken = $fromBackpack > 0 ? $this->withdrawBackpack($characterId, $resourceId, $fromBackpack) : 0;

        if ($backpackTaken < $fromBackpack) {
            // Рюкзак списывается одним условным UPDATE — либо забрал всё
            // запрошенное, либо ничего: откатывать нечего.
            throw new RuntimeException(
                "Недостаточно ресурса #{$resourceId} в рюкзаке: списано {$backpackTaken} из {$fromBackpack} " .
                '(конкурентное списание опустошило остаток между чтением и записью)'
            );
        }

        $storageTaken = $fromStorage > 0 ? $this->withdrawStorage($characterId, $resourceId, $fromStorage) : 0;

        if ($storageTaken < $fromStorage) {
            // Склад отдал меньше обещанного — тот же класс гонки. Откатываем
            // ВСЁ, что уже успели списать: частичная оплата недопустима.
            $this->restoreBackpack($characterId, $resourceId, $backpackTaken);
            $this->restoreStorage($characterId, $resourceId, $storageTaken);

            throw new RuntimeException(
                "Недостаточно ресурса #{$resourceId} на складе: списано {$storageTaken} из {$fromStorage} " .
                '— списание отменено, ресурс возвращён'
            );
        }

        return ['backpack' => $backpackTaken, 'storage' => $storageTaken];
    }

    /** @return array{backpack:int, storage:int} */
    public function consumeByName(int $characterId, string $resourceName, int $qty): array
    {
        $resourceId = $this->resolveResourceId($resourceName);
        if ($resourceId === null) {
            throw new RuntimeException("Неизвестный ресурс: {$resourceName}");
        }

        return $this->consume($characterId, $resourceId, $qty);
    }

    // ── internals (protected — тестовый двойник переопределяет их и не трогает БД) ──

    protected function isPooled(int $characterId): bool
    {
        $enabled = $this->gameSettings->get(self::KILLSWITCH_KEY, true);
        if ($enabled !== true) {
            return false;
        }

        $status = $this->baseCheckService->checkBaseStatus($characterId);

        return ($status['isOnBase'] ?? false) === true;
    }

    protected function backpackQuantity(int $characterId, int $resourceId): int
    {
        $row = $this->characterResourceModel
            ->where('id_characters', $characterId)
            ->where('id_resources', $resourceId)
            ->first();

        if (! is_array($row) || ! isset($row['quantity']) || ! is_numeric($row['quantity'])) {
            return 0;
        }

        return (int) $row['quantity'];
    }

    protected function storageQuantity(int $characterId, int $resourceId): int
    {
        return $this->baseStorageModel->quantityFor($characterId, $resourceId);
    }

    /**
     * Атомарное условное списание из рюкзака: `WHERE quantity >= ?` — строка
     * блокируется на время UPDATE, конкурентное списание той же строки либо
     * увидит уже уменьшённый остаток и получит 0 affected rows, либо
     * применится после нас к свежему значению — расхождение физически
     * невозможно. `CharacterResourceModel::decreaseResources()` тут не
     * годится: он вычитает `amount` безусловно и при недостатке всё равно
     * удаляет строку (см. её докблок) — то самое «списал меньше, чем
     * попросили, но отрапортовал успех», от которого лечит эта стори.
     *
     * @return int реально списано: `$qty` (успех) либо 0 (строки не было
     *             или в ней меньше `$qty` — недостача, ничего не тронуто)
     */
    protected function withdrawBackpack(int $characterId, int $resourceId, int $qty): int
    {
        if ($qty < 1) {
            return 0;
        }

        $db    = \Config\Database::connect();
        $table = $db->prefixTable('character_resources');

        $db->query(
            "UPDATE {$table} SET quantity = quantity - ? WHERE id_characters = ? AND id_resources = ? AND quantity >= ?",
            [$qty, $characterId, $resourceId, $qty]
        );

        if ($db->affectedRows() < 1) {
            return 0;
        }

        // Тот же инвариант, что у decreaseResources(): опустевшая строка не
        // остаётся нулевым остатком.
        $db->query(
            'DELETE FROM ' . $table . ' WHERE id_characters = ? AND id_resources = ? AND quantity <= 0',
            [$characterId, $resourceId]
        );

        return $qty;
    }

    /** @return int реально списано со склада — может быть меньше `$qty` (см. BaseStorageModel::withdraw()) */
    protected function withdrawStorage(int $characterId, int $resourceId, int $qty): int
    {
        return $this->baseStorageModel->withdraw($characterId, $resourceId, $qty);
    }

    /** Откат уже списанного из рюкзака при отказе консьюма (частичная оплата недопустима). */
    protected function restoreBackpack(int $characterId, int $resourceId, int $qty): void
    {
        if ($qty > 0) {
            $this->characterResourceModel->increaseResources($characterId, $resourceId, $qty);
        }
    }

    /** Откат уже списанного со склада при отказе консьюма. */
    protected function restoreStorage(int $characterId, int $resourceId, int $qty): void
    {
        if ($qty > 0) {
            $this->baseStorageModel->deliver($characterId, $resourceId, $qty);
        }
    }

    protected function resolveResourceId(string $resourceName): ?int
    {
        $resource = $this->resourceModel->getResourceByName($resourceName);
        if ($resource === null) {
            return null;
        }

        $id = is_object($resource) ? ($resource->id ?? null) : ($resource['id'] ?? null);

        return is_numeric($id) ? (int) $id : null;
    }
}
