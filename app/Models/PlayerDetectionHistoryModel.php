<?php

namespace App\Models;

use CodeIgniter\Model;

class PlayerDetectionHistoryModel extends Model
{
    protected $table = 'player_detection_history';
    protected $primaryKey = 'id';
    protected $allowedFields = ['detector_player_id', 'detected_player_id', 'detected_at'];
    public $timestamps = false;
}
