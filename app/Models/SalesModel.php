<?php

namespace App\Models;

use CodeIgniter\Model;

class SalesModel extends Model
{
    protected $table = 'sales';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'crafted_item_id',
        'quantity',
        'price',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getSalesByCraftedItemId($craftedItemId)
    {
        return $this->where('crafted_item_id', $craftedItemId)->findAll();
    }

    public function addSale($data)
    {
        return $this->insert($data);
    }

    public function updateSaleQuantity($craftedItemId, $quantity)
    {
        return $this->where('crafted_item_id', $craftedItemId)
            ->set('quantity', 'quantity + ' . $quantity, false)
            ->update();
    }

    public function decreaseSaleQuantity($craftedItemId, $quantity)
    {
        return $this->where('crafted_item_id', $craftedItemId)
            ->set('quantity', 'quantity - ' . $quantity, false)
            ->update();
    }
}
