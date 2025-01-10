<?php

namespace App\Models;

use CodeIgniter\Model;

class ExploredCellsModel extends Model
{
    protected $table = 'explored_cells'; // Имя таблицы
    protected $primaryKey = 'id'; // Первичный ключ таблицы

    protected $useAutoIncrement = true; // Использовать ли автоинкремент для первичного ключа
    protected $returnType     = 'array'; // Тип возвращаемых данных (массив)
    protected $useSoftDeletes = false; // Использовать ли мягкое удаление

    protected $allowedFields = [
        'character_id',
        'telegram_user_id',
        'map_cell_id',
        'biome_id',
        'character_level',
        'cell_status',
        'notes'
    ]; // Поля, разрешенные для массового назначения

    protected $useTimestamps = true; // Использовать ли автоматическое заполнение полей даты создания и редактирования
    protected $createdField  = 'created_at'; // Имя поля для даты создания
    protected $updatedField  = 'updated_at'; // Имя поля для даты редактирования
    protected $deletedField  = ''; // Имя поля для мягкого удаления, если используется

    protected $validationRules    = []; // Правила валидации
    protected $validationMessages = []; // Сообщения валидации
    protected $skipValidation     = false; // Пропускать ли валидацию
}
