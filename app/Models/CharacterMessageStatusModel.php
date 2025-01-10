<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterMessageStatusModel extends Model
{
    protected $table = 'character_message_status';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'character_id',
        'character_data_id',
        'status',
    ];

    protected $useTimestamps = false;

    protected $returnType = 'array';
}
