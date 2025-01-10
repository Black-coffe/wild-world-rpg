<?php

namespace App\Models;

use CodeIgniter\Model;

class PlayerDetectionModel extends Model
{
    protected $table = 'player_detection';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'detector_player_id',
        'detected_player_id',
        'detector_cell',
        'detected_cell',
        'distance',
        'detected_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'detected_at';
}
