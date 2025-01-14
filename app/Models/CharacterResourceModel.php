<?php

namespace App\Models;

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
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // === СТАРЫЕ МЕТОДЫ НЕ ТРОГАЕМ, чтобы не ломать уже существующий код ===

    public function increaseResources($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources'  => $resourceId
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] + $amount;
            return $this->update($existingResource['id'], ['quantity' => $newQuantity]);
        } else {
            return $this->insert([
                'id_characters' => $characterId,
                'id_resources'  => $resourceId,
                'quantity'      => $amount
            ]);
        }
    }

    public function decreaseResources($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources'  => $resourceId
        ])->first();

        if ($existingResource) {
            $newQuantity = $existingResource['quantity'] - $amount;

            if ($newQuantity <= 0) {
                return $this->delete($existingResource['id']);
            } else {
                return $this->update($existingResource['id'], ['quantity' => $newQuantity]);
            }
        } else {
            log_message('error', "Attempt to decrease non-existing resource for character $characterId and resource $resourceId");
            return false;
        }
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

        $result = $query->getRow();
        return $result ? (int)$result->total_quantity : 0;
    }

    public function addOrIncreaseResource($characterId, $resourceId, $amount)
    {
        $existingResource = $this->where([
            'id_characters' => $characterId,
            'id_resources'  => $resourceId
        ])->first();

        if ($existingResource) {
            return $this->update($existingResource['id'], [
                'quantity' => $existingResource['quantity'] + $amount,
            ]);
        } else {
            return $this->insert([
                'id_characters' => $characterId,
                'id_resources'  => $resourceId,
                'quantity'      => $amount,
            ]);
        }
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
     * СТАРЫЙ метод (не меняем!). Списывает ресурсы, но страдает той же проблемой alias'ов...
     */
    public function deductResource($resourceName, $characterId, $amount)
    {
        $this->db->transStart();
        $resource = $this->getResourceByNameAndCharacterId($resourceName, $characterId);

        if ($resource && isset($resource['quantity']) && $resource['quantity'] >= $amount) {
            $newQuantity = $resource['quantity'] - $amount;

            if (!is_int($resource['id'])) {
                $resourceId = (int) $resource['id'];
            } else {
                $resourceId = $resource['id'];
            }

            $affectedRows = $this->update($resourceId, ['quantity' => $newQuantity]);

            $this->db->transComplete();
            return ($affectedRows > 0);
        }
        $this->db->transRollback();
        return false;
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
     * Новый метод для "безопасного" списания ресурса через alias'ы.
     */
    public function deductResourceForCraft(string $resourceName, int $characterId, int $amount): bool
    {
        $this->db->transStart();

        $row = $this->getResourceForCraft($resourceName, $characterId);
        if (!$row) {
            $this->db->transRollback();
            return false;
        }

        $charResId  = (int) $row['charResId'];
        $currentQty = (int) $row['charResQty'];

        if ($currentQty < $amount) {
            $this->db->transRollback();
            return false;
        }

        $newQty = $currentQty - $amount;

        if ($newQty <= 0) {
            $this->delete($charResId);
        } else {
            $this->update($charResId, ['quantity' => $newQty]);
        }

        $this->db->transComplete();
        return true;
    }
}
