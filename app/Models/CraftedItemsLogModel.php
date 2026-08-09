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
        'quantity',
        'insured',
        'custom_setting',
    ];

    // Настройки даты и времени
    protected $useTimestamps = true; // Включить автоматическое управление метками времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления

    // Правила валидации данных
    protected $validationRules = [
        'character_id'       => 'required|is_natural_no_zero',
        'task_id'            => 'permit_empty',
        'crafted_item_id'    => 'required|is_natural_no_zero',
        'type'               => 'permit_empty|string|max_length[100]',
        'direction_craft'    => 'permit_empty|string|max_length[100]',
        'crafting_location'  => 'permit_empty|string|max_length[255]',
        'durability_count'   => 'permit_empty|integer',
        'durability_time'    => 'permit_empty|valid_date',
        'quantity'           => 'required|integer',
        'custom_setting'     => 'permit_empty|string',
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

    /**
     * Списывает количество у крафтового предмета, используя crafted_item_id (числовой ID), а не name_eng.
     *
     * @param int $craftedItemId Идентификатор предмета в таблице crafted_items
     * @param int $characterId   Идентификатор персонажа
     * @param int $quantity      Сколько списать
     * @return bool Успешность списания
     */
    public function deductCraftedItem(int $craftedItemId, int $characterId, int $quantity): bool
    {
        $this->db->transStart();

        // Ищем запись о конкретном предмете в crafted_items_log:
        $logRow = $this->where('crafted_item_id', $craftedItemId)
            ->where('character_id', $characterId)
            ->first();

        if (!$logRow) {
            // Предмет не найден в логе
            $this->db->transRollback();
            return false;
        }

        $currentQty = (int)$logRow['quantity'];
        if ($currentQty < $quantity) {
            // Недостаточно предметов для списания
            $this->db->transRollback();
            return false;
        }

        $newQty = $currentQty - $quantity;
        if ($newQty <= 0) {
            // Удаляем запись, если предметов больше нет
            $this->delete($logRow['id']);
        } else {
            // Обновляем количество
            $this->update($logRow['id'], ['quantity' => $newQty]);
        }

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /**
     * Сколько доз/зарядов несёт ОДНА единица предмета по шаблону `crafted_items`.
     * Ноль/NULL/мусор в шаблоне = одноразовый предмет (1 доза), а не «бесконечный».
     */
    public static function baseCharges(mixed $templateDurability): int
    {
        return is_numeric($templateDurability) && (int) $templateDurability > 0
            ? (int) $templateDurability
            : 1;
    }

    /**
     * Фактический остаток зарядов у строки стака — ЗАЖАТЫЙ ёмкостью шаблона.
     *
     * 🔴 `crafted_items_log.durability_count` — НЕ гарантия диапазона. Часть строк
     * несёт историческую константу 100 от старой выдачи (PvE-награда до v0.51.593
     * писала 100 всем предметам, а при пустом шаблоне пишет её и сейчас). Для
     * одноразового медикамента (база 1) это превращалось в 100 бесплатных доз:
     * экран показывал «1 шт.», а предмет не заканчивался (багрепорт про «бесконечный
     * медовый сбитень», 2026-08-09).
     *
     * Правило: `min(остаток, база)`, снизу — 1 (строка с 0 всё ещё тратится как
     * последняя доза, поведение не меняется).
     */
    public static function effectiveCharges(mixed $storedDurability, mixed $templateDurability): int
    {
        $base   = self::baseCharges($templateDurability);
        $stored = is_numeric($storedDurability) ? (int) $storedDurability : $base;

        return max(1, min($stored, $base));
    }
}
