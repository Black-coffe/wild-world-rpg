<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * WB4 (ADR-137 «Узлы») — фиксированные точки-узлы мировых боссов.
 *
 * Узел = постоянная точка территории; `current_level` = число расчисток (память точки).
 * Гео-эскалация: `base_level` = f(`y_band`). Респавн/эскалацию ведёт NodeRespawnHandler (WB5),
 * бой инициирует игрок (WB6). WipeManifest: SEED_RESET.
 */
class BossPointModel extends Model
{
    protected $table            = 'boss_points';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'cell_number',
        'coordinate_x',
        'coordinate_y',
        'biome_id',
        'y_band',
        'base_level',
        'current_level',
        'current_health',
        'max_health',
        'status',
        'respawn_at',
        'last_killer_character_id',
        'kill_count',
        'current_npc_id',
    ];
}
