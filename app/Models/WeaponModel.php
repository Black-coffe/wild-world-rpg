<?php

namespace App\Models;

use CodeIgniter\Model;

class WeaponModel extends Model
{
    // Название таблицы, с которой работает модель
    protected $table            = 'weapons';
    // Первичный ключ
    protected $primaryKey       = 'id';
    // Автоинкремент (true — если PK это AI)
    protected $useAutoIncrement = true;
    // Тип возвращаемых данных: массив (или 'object')
    protected $returnType       = 'array';

    // Если хотите автозаполнение полей created_at / updated_at через модель:
    // protected $useTimestamps = true;
    // protected $createdField  = 'created_at';
    // protected $updatedField  = 'updated_at';

    /**
     * Поля, доступные для массового заполнения (insert, update).
     * Остальные поля, не указанные здесь, при использовании
     * $model->save(), $model->insert() и т.д. игнорируются.
     */
    protected $allowedFields = [
        'name',
        'name_en',
        'description',
        'weapon_type',
        'damage_value',
        'damage_type',
        'range_value',
        'attack_speed',
        'special_effect',
        'effect_type',
        'limitation',
        'limitation_type',
        'required_strength',
        'required_agility',
        'required_intellect',
        'required_level',
        'rarity',
        'durability',
        'durability_max',
        'weight',
        'price',
        'ammo_type',
        'created_at',
        'updated_at',
    ];

    /**
     * Получает запись оружия по ID (первичный ключ).
     *
     * @param int $id  Первичный ключ (ID)
     * @return array|null  Запись в виде массива или null, если не найдена
     */
    public function getById(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * Получает запись оружия по английскому названию (slug).
     *
     * @param string $englishName  Значение поля name_en
     * @return array|null  Запись или null, если не найдена
     */
    public function getByEnglishName(string $englishName): ?array
    {
        return $this->where('name_en', $englishName)->first();
    }
}
