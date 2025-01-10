<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Models\CraftedItemsModel;

class CraftedItemsLogModel extends Model
{
    protected $table = 'crafted_items_log'; // Имя таблицы
    protected $primaryKey = 'id'; // Первичный ключ

    protected $useAutoIncrement = true; // Использование автоинкремента для первичного ключа

    protected $returnType = 'array'; // Тип возвращаемых данных

    protected $allowedFields = [
        'character_id',
        'task_id',
        'crafted_item_id',
        'type',
        'direction_craft',
        'crafting_location',
        'durability_count',
        'durability_time',
        'quantity'
    ]; // Разрешенные для изменения поля

    // Настройки даты и времени
    protected $useTimestamps = true; // Включить автоматическое управление метками времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления

    // Правила валидации данных
    protected $validationRules = [
        'character_id'       => 'required|is_natural_no_zero',
        'task_id'            => 'required|is_natural_no_zero',
        'crafted_item_id'    => 'required|is_natural_no_zero',
        'type'               => 'permit_empty|string|max_length[100]',
        'direction_craft'    => 'permit_empty|string|max_length[100]',
        'crafting_location'  => 'permit_empty|string|max_length[255]',
        'durability_count'   => 'permit_empty|integer',
        'durability_time'    => 'permit_empty|valid_date',
        'quantity'           => 'required|integer'
    ];

    protected $validationMessages = [];

    /**
     * Get a crafted item by its name and associated character ID.
     *
     * @param string $itemName Name of the crafted item.
     * @param int $characterId ID of the character.
     * @return array|null The crafted item data or null if not found.
     */
    public function getItemByCraftedItemIdAndCharacterId(int $craftedItemId, int $characterId)
    {
        return $this->where('crafted_item_id', $craftedItemId)
            ->where('character_id', $characterId)
            ->first();
    }

    /**
     * Get a crafted item by its English name and associated character ID.
     *
     * @param string $itemName English name of the crafted item.
     * @param int $characterId ID of the character.
     * @return array|null The crafted item data or null if not found.
     */
    public function getItemByNameEngAndCharacterId(string $itemName, int $characterId)
    {
        $itemModel = new CraftedItemsModel();
        $item = $itemModel->where('name_eng', $itemName)->first();

        if (!$item) {
            return null;
        }

        return $this->where('crafted_item_id', $item['id'])
            ->where('character_id', $characterId)
            ->first();
    }

    public function subtractItem($characterId, $itemName, $subtractQuantity) {
        $itemModel = new CraftedItemsModel();
        $item = $itemModel->where('name_eng', $itemName)->first();

        if (!$item) {
            return false; // предмет не найден
        }

        $logEntry = $this->where([
            'character_id' => $characterId,
            'crafted_item_id' => $item['id']
        ])->first();

        if (!$logEntry) {
            return false; // запись о предмете не найдена
        }

        if ($logEntry['quantity'] < $subtractQuantity) {
            return false; // недостаточно предметов для списания
        }

        $newQuantity = $logEntry['quantity'] - $subtractQuantity;

        if ($newQuantity > 0) {
            // Обновляем количество предмета
            $this->update($logEntry['id'], ['quantity' => $newQuantity]);
        } else {
            // Удаляем запись, если предметов больше нет
            $this->delete($logEntry['id']);
        }

        return true; // успешное списание
    }
}
