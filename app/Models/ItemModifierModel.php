<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * W19 (ADR-074) — модификаторы экземпляров предметов (player-facing «Модернизация»).
 *
 * Одна строка = один модификатор на экземпляр (UNIQUE item_type+item_instance_id).
 * `item_instance_id` = `characters_weapons.id` (per-instance).
 */
class ItemModifierModel extends Model
{
    protected $table         = 'item_modifiers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    protected $allowedFields = [
        'character_id',
        'item_type',
        'item_instance_id',
        'stat',
        'bonus_pct',
        'created_at',
        'updated_at',
    ];

    /**
     * Модификатор экземпляра (или null).
     *
     * @return array<string,mixed>|null
     */
    public function findForInstance(string $itemType, int $instanceId): ?array
    {
        $row = $this->where('item_type', $itemType)
            ->where('item_instance_id', $instanceId)
            ->first();
        if (! is_array($row)) {
            return null;
        }
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
