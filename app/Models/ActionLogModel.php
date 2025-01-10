<?php

namespace App\Models;

use CodeIgniter\Model;

class ActionLogModel extends Model
{
    protected $table = 'action_log';  // Название таблицы
    protected $primaryKey = 'id';  // Первичный ключ
    protected $allowedFields = [
        'character_id',
        'chat_id',
        'action_name',
        'action_status',
        'description',
        'created_at',
        'updated_at'
    ];  // Разрешенные к изменению поля

    protected $useAutoIncrement = true;  // Использование автоинкремента для первичного ключа
    protected $useTimestamps = true;  // Включение автоматического управления временными метками
    protected $createdField  = 'created_at';  // Поле для временной метки создания
    protected $updatedField  = 'updated_at';  // Поле для временной метки обновления
    protected $dateFormat = 'datetime';  // Формат даты и времени

    // Если тебе требуется кастомизация ввода и вывода данных, ты можешь использовать модификаторы данных
}
