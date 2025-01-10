<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterNamesModel extends Model
{
    protected $table = 'character_names'; // Имя таблицы
    protected $primaryKey = 'id'; // Первичный ключ

    // Включение автоинкремента для первичного ключа
    protected $useAutoIncrement = true;

    // Тип возвращаемого значения: массив
    protected $returnType = 'array';

    // Разрешенные поля для изменения
    protected $allowedFields = ['character_name', 'status', 'created_at', 'updated_at'];

    // Управление временными метками
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    // Валидация
    protected $validationRules = [
        'character_name' => 'required|alpha_numeric_punct|min_length[3]|max_length[20]|is_unique[character_names.character_name]',
        'status' => 'in_list[free, occupied]',
    ];

    protected $validationMessages = [
        'character_name' => [
            'required' => 'Имя персонажа обязательно.',
            'alpha_numeric_punct' => 'Имя персонажа может содержать только латинские буквы, цифры и знак подчеркивания.',
            'min_length' => 'Имя персонажа должно быть не менее 3 символов.',
            'max_length' => 'Имя персонажа должно быть не более 20 символов.',
            'is_unique' => 'Это имя персонажа уже занято.',
        ],
        'status' => [
            'in_list' => 'Статус должен быть либо "free", либо "occupied".',
        ],
    ];

    // Пропуск валидации (если требуется)
    protected $skipValidation = false;

    // Методы модели могут быть добавлены ниже для работы с этой таблицей.
}
