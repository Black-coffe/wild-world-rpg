<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterTaskModel extends Model
{
    protected $table = 'character_tasks'; // Имя таблицы
    protected $primaryKey = 'id'; // Первичный ключ

    protected $useAutoIncrement = true; // Использование автоинкремента для первичного ключа
    protected $returnType = 'array'; // Тип возвращаемого результата

    protected $allowedFields = [ // Разрешенные поля для массового назначения
        'character_id',
        'telegram_user_id',
        'task_id',
        'start_time',
        'end_time',
        'status',
    ];

    protected $useTimestamps = true; // Использовать автоматические метки времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления

    // Правила валидации
    protected $validationRules = [
        'character_id'        => 'required|integer',
        'telegram_user_id'    => 'required|integer',
        'task_id'             => 'required|integer',
        'start_time'          => 'required|valid_date',
        'end_time'            => 'permit_empty|valid_date',
        'status'              => 'required|in_list[in_work,completed,interrupted]',
    ];

    // Сообщения валидации
    protected $validationMessages = [
        'character_id'        => [
            'required' => 'Character ID is required.',
            'integer'  => 'Character ID must be an integer.',
        ],
        'telegram_user_id'    => [
            'required' => 'Telegram User ID is required.',
            'integer'  => 'Telegram User ID must be an integer.',
        ],
        'task_id'             => [
            'required' => 'Task ID is required.',
            'integer'  => 'Task ID must be an integer.',
        ],
        'start_time'          => [
            'required'    => 'Start time is required.',
            'valid_date'  => 'Start time must be a valid date.',
        ],
        'end_time'            => [
            'permit_empty' => 'End time is optional.',
            'valid_date'   => 'End time must be a valid date if provided.',
        ],
        'status'              => [
            'required' => 'Status is required.',
            'in_list'  => 'Status must be one of: in_work, completed, interrupted.',
        ],
    ];

    protected $skipValidation = false; // Пропускать ли валидацию
}
