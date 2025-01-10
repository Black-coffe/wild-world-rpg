<?php

namespace App\Models;

use CodeIgniter\Model;

class TaskModel extends Model
{
    protected $table = 'tasks'; // Название таблицы
    protected $primaryKey = 'id'; // Первичный ключ

    protected $useAutoIncrement = true; // Использовать ли автоинкремент
    protected $returnType = 'array'; // Возвращаемый тип данных при запросе
    protected $useSoftDeletes = false; // Использовать ли мягкое удаление

    protected $allowedFields = [ // Поля, разрешенные для массового назначения
        'name',
        'name_rus',
        'description',
        'min_duration',
        'max_duration',
        'type',
        'difficulty_level',
        'execution_limit',
        'parallel_execution_allowed',
        'interruptible',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true; // Использовать ли автоматические метки времени
    protected $createdField  = 'created_at'; // Поле для метки времени создания
    protected $updatedField  = 'updated_at'; // Поле для метки времени обновления
    protected $deletedField  = ''; // Поле для метки времени удаления (если используется мягкое удаление)

    protected $validationRules    = [ // Правила валидации
        'name' => 'required|string|max_length[255]',
        'name_rus' => 'required|string|max_length[255]',
        'description' => 'permit_empty|string',
        'min_duration' => 'required|integer',
        'max_duration' => 'required|integer',
        'type' => 'required|string|max_length[255]',
        'difficulty_level' => 'required|integer',
        'execution_limit' => 'required|integer',
        'parallel_execution_allowed' => 'required',
        'interruptible' => 'required'
    ];

    protected $validationMessages = []; // Сообщения валидации
    protected $skipValidation     = false; // Пропускать ли валидацию
}
