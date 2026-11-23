<?php

namespace App\Models;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use CodeIgniter\Model;

class CharacterResourceModel extends Model
{
    protected $table = 'character_resources';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $allowedFields = [
        'id_characters',
        'id_resources',
        'quantity',
        'custom_data',
        'created_at',
        'updated_at'
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // --- (ВСЕ ВАШИ СТАРЫЕ МЕТОДЫ И СУЩЕСТВУЮЩИЕ НОВЫЕ - БЕЗ ИЗМЕНЕНИЙ) ---

    /**
     * Новый метод-обёртка, чтобы не ломать вызовы deductResourceByName(...)
     */
    public function deductResourceByName(string $resourceName, int $characterId, int $amount): bool
    {
        // Внутри используем ваш метод deductResourceForCraft
        // Если вы хотите иное поведение, скопируйте нужную логику сюда
        return $this->deductResourceForCraft($resourceName, $characterId, $amount);
    }

    /**
     * s1-atomicity-02 (C8): начисление одной инструкцией по `UNIQUE (id_characters, id_resources)`
     * — два одновременных начисления складываются, вторая строка пары невозможна. Возврат прежний:
     * id новой строки, если пары не было, `true`, если прибавили к существующей.
     */
    public function increaseResources($characterId, $resourceId, $amount)
    {
        return $this->upsertQuantity($characterId, $resourceId, $amount);
    }

    /**
     * exploit-fix-06 (ADR-181 §3) — заменяет удалённый `decreaseResources()`. Тот читал строку
     * через `first()`, вычитал в PHP и писал — при `$amount > quantity` не отказывал, а удалял
     * строку и рапортовал успех (`bool`, который ни один из семи вызывающих не читал). Здесь
     * решение принимает один условный `UPDATE` с проверкой `affectedRows()`
     * ({@see ConditionalWriteService::decrementIfAtLeast()}) — прочитанный ниже остаток лишь
     * находит id строки для `WHERE id = ?`, на исход списания он не влияет.
     */
    public function decrementIfAtLeast(int $characterId, int $resourceId, int $amount): WriteOutcome
    {
        // exploit-fix-24: `(id_characters, id_resources)` не несёт UNIQUE — `first()` без
        // `orderBy` возвращает произвольную строку, если пара продублирована. `orderBy('id')`
        // делает выбор детерминированным: всегда одна и та же строка (самая старая пары),
        // а не молча меняющийся id между вызовами при одинаковых данных.
        $row = $this->where([
            'id_characters' => $characterId,
            'id_resources'  => $resourceId,
        ])->orderBy('id', 'ASC')->first();

        $rowId = is_array($row) && isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        if ($rowId <= 0) {
            return WriteOutcome::Missing;
        }

        return (new ConditionalWriteService($this->db))->decrementIfAtLeast(
            $this->table,
            $rowId,
            'quantity',
            $amount,
            true
        );
    }

    public function getTotalResourcesByRarity($characterId, $resourceRarity)
    {
        $db = \Config\Database::connect();
        $builder = $db->table($this->table);
        $builder->select('character_resources.id_characters, SUM(character_resources.quantity) as total_quantity');
        $builder->join('resources', 'character_resources.id_resources = resources.id', 'inner');
        $builder->where('character_resources.id_characters', $characterId);
        $builder->where('resources.rarity', $resourceRarity);
        $builder->groupBy('character_resources.id_characters');

        $query = $builder->get();
        if ($query === false) {
            return 0;
        }

        $result = $query->getRow();
        return $result ? (int)$result->total_quantity : 0;
    }

    /** s1-atomicity-02 (C8): то же атомарное начисление, что {@see increaseResources()}. */
    public function addOrIncreaseResource($characterId, $resourceId, $amount)
    {
        return $this->upsertQuantity($characterId, $resourceId, $amount);
    }

    /**
     * `INSERT … ON DUPLICATE KEY UPDATE quantity = quantity + ?` (параметр, не `VALUES()`).
     * `affectedRows()`: 1 — вставлена новая строка (возврат — её id, как у прежнего `insert()`),
     * 2 или 0 — существующая строка пары (возврат `true`, как у прежнего `update()`).
     *
     * @return bool|int
     */
    private function upsertQuantity(mixed $characterId, mixed $resourceId, mixed $amount): bool|int
    {
        $now   = date('Y-m-d H:i:s');
        $table = $this->db->prefixTable($this->table);

        $result = $this->db->query(
            "INSERT INTO {$table} (id_characters, id_resources, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE quantity = quantity + ?, updated_at = ?',
            [$characterId, $resourceId, $amount, $now, $now, $amount, $now]
        );
        if ($result === false) {
            return false;
        }

        return $this->db->affectedRows() === 1 ? (int) $this->db->insertID() : true;
    }

    /**
     * СТАРЫЙ метод (не меняем!).
     * Но он возвращает conflict: 'id' (из resources), 'id' (из character_resources).
     */
    public function getResourceByNameAndCharacterId($resourceName, $characterId)
    {
        return $this
            ->select('character_resources.*, resources.*')
            ->join('resources', 'resources.id = character_resources.id_resources')
            ->where('resources.name', $resourceName)
            ->where('character_resources.id_characters', $characterId)
            ->first();
    }

    /**
     * s1-atomicity-02 (C8): списание условной записью `quantity >= n` в самом UPDATE. Нулевой
     * остаток строку не удаляет — как и раньше. Прежняя форма читала строку через
     * {@see getResourceByNameAndCharacterId()}, где `id` перекрыт `resources.id`, и писала не ту
     * строку; id строки персонажа здесь берётся алиасом `charResId`.
     */
    public function deductResource($resourceName, $characterId, $amount)
    {
        return $this->deductByName((string) $resourceName, (int) $characterId, (int) $amount, false);
    }

    // === ДОБАВЛЯЕМ НОВЫЙ метод, который работает через alias'ы: ===

    /**
     * Новый метод для крафта (или любого механизма),
     * где возвращаются алиасы: charResId, charResQty, resourceId, resourceName, resourceRarity.
     * Он НЕ ЛОМАЕТ старые методы, т.к. у них другая сигнатура/название.
     */
    public function getResourceForCraft(string $resourceName, int $characterId)
    {
        return $this
            ->select(
                'character_resources.id AS charResId, ' .
                'character_resources.quantity AS charResQty, ' .
                'resources.id AS resourceId, ' .
                'resources.name AS resourceName, ' .
                'resources.rarity AS resourceRarity'
            )
            ->join('resources', 'resources.id = character_resources.id_resources')
            ->where('resources.name', $resourceName)
            ->where('character_resources.id_characters', $characterId)
            ->first();
    }

    /**
     * Списание для крафта: s1-atomicity-02 (C8) — условной записью, опустошённая строка удаляется.
     * `false` — строки нет или не хватило.
     */
    public function deductResourceForCraft(string $resourceName, int $characterId, int $amount): bool
    {
        return $this->deductByName($resourceName, $characterId, $amount, true);
    }

    /**
     * Уменьшает quantity строки `$charResId` на `$amount`; опустошённая строка удаляется.
     * s1-atomicity-02 (C8): условной записью — при `quantity < $amount` отказ (`false`), а не
     * удаление «всего, что было».
     */
    public function decreaseQtyById(int $charResId, int $amount): bool
    {
        return $this->deductRow($charResId, $amount, true);
    }

    private function deductByName(string $resourceName, int $characterId, int $amount, bool $deleteWhenEmpty): bool
    {
        $row   = $this->getResourceForCraft($resourceName, $characterId);
        $rowId = is_array($row) && is_numeric($row['charResId'] ?? null) ? (int) $row['charResId'] : 0;
        if ($rowId <= 0) {
            return false;
        }

        return $this->deductRow($rowId, $amount, $deleteWhenEmpty);
    }

    /**
     * `ConditionalWriteService::decrementIfAtLeast()` — решает `WHERE quantity >= ?` и
     * `affectedRows()`, не прочитанный остаток. `$amount <= 0` примитив отвергает; прежние методы
     * на нём отвечали успехом без списания — так и оставлено.
     */
    private function deductRow(int $rowId, int $amount, bool $deleteWhenEmpty): bool
    {
        if ($amount <= 0) {
            return $this->find($rowId) !== null;
        }

        return (new ConditionalWriteService($this->db))->decrementIfAtLeast(
            $this->table,
            $rowId,
            'quantity',
            $amount,
            $deleteWhenEmpty
        ) === WriteOutcome::Applied;
    }
}
