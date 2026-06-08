<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-101 Фаза 3 (рефайн) — ассортимент фракционных лавок оплотов (settlement_shop_items).
 *
 * @see App\Services\Settlement\SettlementShopService
 */
class SettlementShopItemModel extends Model
{
    protected $table         = 'settlement_shop_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'settlement_id',
        'item_type',
        'item_id',
        'base_price',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    /**
     * Ассортимент конкретного поселения (по порядку sort_order).
     *
     * @return list<array<int|string,mixed>>
     */
    public function forSettlement(int $settlementId): array
    {
        $out = [];
        foreach ($this->where('settlement_id', $settlementId)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
