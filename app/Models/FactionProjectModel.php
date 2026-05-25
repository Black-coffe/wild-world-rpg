<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * V20 (ADR-051) — faction_projects: общий проект снабжения (один-на-фракцию).
 */
class FactionProjectModel extends Model
{
    protected $table         = 'faction_projects';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'faction_id',
        'progress',
        'buff_until',
        'cycles_completed',
    ];
}
