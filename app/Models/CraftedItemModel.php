<?php

namespace App\Models;

use CodeIgniter\Model;

class CraftedItemModel extends Model
{
    protected $table = 'crafted_items'; // Название таблицы
    protected $primaryKey = 'id'; // Первичный ключ

    protected $useAutoIncrement = true; // Использование автоинкремента для первичного ключа
    protected $returnType = 'array'; // Тип возвращаемых данных (может быть 'array' или 'object')
    protected $useSoftDeletes = false; // Не использовать мягкое удаление

    protected $allowedFields = [
        'name_rus', 'name_eng', 'type', 'direction_craft', 'effect', 'gathering_speed',
        'durability_count', 'durability_time', 'type_of_battle', 'damage', 'armor', 'hp',
        'character_boost', 'weight', 'price', 'stack_size', 'required_level', 'required_skills',
        'required_resources', 'crafting_time', 'crafting_location', 'description'
    ]; // Поля, доступные для изменения

    protected $useTimestamps = true; // Использование автоматических меток времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления

    // Валидация данных
    protected $validationRules    = [];
    protected $validationMessages = [];
    protected $skipValidation     = false;

    /**
     * Дополнительные методы для работы с данными можно добавлять здесь.
     */
}
