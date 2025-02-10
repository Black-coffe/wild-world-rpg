<?php

namespace App\Models;

use CodeIgniter\Model;

class OutfitModel extends Model
{
    protected $table            = 'outfits';   // Название таблицы
    protected $primaryKey       = 'id';        // Первичный ключ
    protected $useAutoIncrement = true;        // Автоинкремент
    protected $returnType       = 'array';     // Тип возвращаемых данных ('array' или 'object')

    /**
     * Если используете timestamps (created_at, updated_at)
     * и хотите, чтобы их автоматически проставляла модель — раскомментируйте:
     */
    // protected $useTimestamps = true;
    // protected $createdField  = 'created_at';
    // protected $updatedField  = 'updated_at';

    // Какие поля разрешены для массового заполнения (insert, update)
    protected $allowedFields = [
        'name',
        'name_en',
        'description',
        'armor_type',
        'slot',
        'armor_value',
        'physical_resistance',
        'fire_resistance',
        'poison_resistance',
        'speed_modifier',
        'stealth_modifier',
        'weight',
        'durability',
        'durability_max',
        'required_strength',
        'required_level',
        'rarity',
        'bonus_type',
        'special_bonus',
        'penalty_type',
        'penalty',
        'price',
        // 'created_at',
        // 'updated_at',
    ];

    /**
     * Возвращает одну запись по значению поля name_en.
     *
     * @param string $enName  Значение поля name_en.
     * @return array|null     Массив данных или null, если не найдено.
     */
    public function getByEnglishName(string $enName): ?array
    {
        return $this->where('name_en', $enName)->first();
    }

    /**
     * Возвращает запись по ID (первичному ключу).
     * Можно вызывать find($id), но для наглядности делаем отдельный метод.
     *
     * @param int $id  Первичный ключ
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        return $this->find($id);
    }
}
