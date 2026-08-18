<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Состояния персонажа, которые не лечатся едой (`character_debuffs`).
 *
 * Каталог — `Config\Debuffs`, вся логика — `App\Services\Player\DebuffService`.
 * Строка живёт после лечения: `cured_at` / `expired_at` заполняются, а не удаляются,
 * чтобы «получают, но не лечат» отличалось от «не получают вовсе».
 */
class CharacterDebuffModel extends Model
{
    protected $table         = 'character_debuffs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'character_id',
        'debuff_key',
        'severity',
        'source',
        'applied_at',
        'expires_at',
        'last_tick_at',
        'cured_at',
        'cured_by_item',
        'expired_at',
    ];

    /**
     * Активные состояния персонажа: не вылечены, не истекли.
     *
     * @return list<array<array-key, mixed>>
     */
    public function activeFor(int $characterId): array
    {
        $rows = $this->where('character_id', $characterId)
            ->where('cured_at', null)
            ->where('expired_at', null)
            ->orderBy('applied_at', 'ASC')
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
