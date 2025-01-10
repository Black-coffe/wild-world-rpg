<?php

namespace App\Models;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    protected $table = 'transactions';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'character_id',
        'crafted_item_id',
        'type',
        'quantity',
        'price',
        'transaction_date',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getTransactionsByCharacterId($characterId)
    {
        return $this->where('character_id', $characterId)->findAll();
    }

    public function getTransactionsByCraftedItemId($craftedItemId)
    {
        return $this->where('crafted_item_id', $craftedItemId)->findAll();
    }

    public function addTransaction($data)
    {
        return $this->insert($data);
    }
}
