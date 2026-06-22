<?php

namespace App\Models;

use CodeIgniter\Model;

class CharactersWeaponsModel extends Model
{
    protected $table            = 'characters_weapons';  // название таблицы
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    // Если хотим автоматически заполнять поля created_at / updated_at:
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Разрешённые поля для массового заполнения.
     * Остальные поля, не указанные здесь, будут игнорироваться при insert/update.
     */
    protected $allowedFields = [
        'character_id',
        'weapon_id',
        'quantity',
        'current_durability',
        'equipped',
        'slot',
        'is_soulbound', // WB2 (ADR-137): трофеи-узлы «не продаётся/не теряется»
        // если включали ammo_in_mag:
        // 'ammo_in_mag',
    ];

    /**
     * Пример метода для получения записи по (character_id, weapon_id).
     */
    public function getByCharacterAndWeapon(int $characterId, int $weaponId): ?array
    {
        return $this->where('character_id', $characterId)
            ->where('weapon_id', $weaponId)
            ->first();
    }

    /**
     * Пример метода увеличения/уменьшения quantity.
     */
    public function adjustQuantity(int $id, int $amount)
    {
        $row = $this->find($id);
        if (!$row) {
            return false;
        }
        $newQty = max(0, $row['quantity'] + $amount);

        // Если qty стало 0, можете либо удалять запись, либо хранить qty=0
        if ($newQty === 0) {
            return $this->delete($id);
        } else {
            return $this->update($id, ['quantity' => $newQty]);
        }
    }
}
