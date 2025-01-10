<?php

namespace App\Models;

use CodeIgniter\Model;

class CraftedItemsModel extends Model
{
    protected $table = 'crafted_items';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'name_rus', 'name_eng', 'type', 'direction_craft', 'effect', 'gathering_speed',
        'durability_count', 'durability_time', 'type_of_battle', 'damage', 'armor', 'hp',
        'character_boost', 'weight', 'price', 'stack_size', 'required_level', 'required_skills',
        'required_resources', 'crafting_time', 'crafting_location', 'description'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules    = [];
    protected $validationMessages = [];
    protected $skipValidation     = false;

    /**
     * Retrieve the ID of an item by its English name.
     *
     * @param string $itemName The English name of the item.
     * @return int|null The ID of the item or null if not found.
     */
    public function getIdByName(string $itemName)
    {
        $item = $this->where('name_eng', $itemName)->first();
        return $item ? (int) $item['id'] : null;
    }

    public function getRowByName(string $itemName)
    {
        return $this->where('name_eng', $itemName)->first();
    }

    public function getCraftedItemByName(string $itemName)
    {
        return $this->where('name_rus', $itemName)->first();
    }

    public function getItemByNameEngAndCharacterId($itemName, $characterId)
    {
        return $this->where('name_eng', $itemName)
            ->join('crafted_items_log', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where('crafted_items_log.character_id', $characterId)
            ->first();
    }

    /**
     * Adds to or increases the quantity of a crafted item for a character.
     *
     * @param int $characterId Character ID to add items for.
     * @param string $itemName Name of the crafted item.
     * @param int $quantity Quantity to add.
     */
    public function addOrIncreaseItem($characterId, $itemName, $quantity, $taskId = null) {
        $item = $this->where('name_eng', $itemName)->first();

        if (!$item) {
            return false; // Item does not exist
        }

        $builder = $this->db->table('crafted_items_log');
        $existing = $builder->where('character_id', $characterId)
            ->where('crafted_item_id', $item['id'])
            ->get()
            ->getRow();

        if ($existing) {
            $newQuantity = $existing->quantity + $quantity;
            $builder->where('id', $existing->id)->update(['quantity' => $newQuantity]);
        } else {
            $data = [
                'character_id' => $characterId,
                'crafted_item_id' => $item['id'],
                'quantity' => $quantity,
                'task_id' => null // Make sure this is a valid task_id or handle it if null is not allowed
            ];
            // Check if task_id is required and not null
//            if ($taskId === null && $this->db->getFieldData('crafted_items_log')['task_id']->is_nullable === FALSE) {
//                // Handle case where task_id is required but not provided
//                log_message('error', 'Task ID is required and not provided.');
//                return false;
//            }
            $builder->insert($data);
        }

        return true;
    }

}
