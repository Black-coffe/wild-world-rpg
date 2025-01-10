<?php

namespace App\Models;

use CodeIgniter\Model;

class CharacterDataModel extends Model
{
    protected $table = 'character_data';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'character_id',
        'coordinate_x',
        'coordinate_y',
        'biome',
        'explored_cells',
        'unique_resources',
        'days_played',
        'character_level',
        'experience_points',
        'health',
        'strength',
        'agility',
        'intelligence',
        'endurance',
        'closed_tasks',
        'interrupted_tasks',
        'message_text',
        'message_image_path',
        'message_buttons',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $returnType = 'array';
}
