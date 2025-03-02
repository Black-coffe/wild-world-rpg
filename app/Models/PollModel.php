<?php
namespace App\Models;

use CodeIgniter\Model;

class PollModel extends Model
{
    protected $table = 'polls';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // Поля, которые разрешено массово заполнять (при создании или обновлении)
    protected $allowedFields = ['question', 'active', 'expires_at'];

    // В данной таблице автоматическое заполнение временных полей не требуется,
    // поскольку у нас используется только поле created_at, которое устанавливается в БД.
    protected $useTimestamps = false;
}
