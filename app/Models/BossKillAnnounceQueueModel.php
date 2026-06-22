<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * WB11 (ADR-137 «Узлы») — очередь анонсов о повергнутых узлах.
 *
 * kill-путь складывает квалифицирующий килл (`boss_level ≥ world.nodes.announce_min_level`),
 * NodeAnnounceDigestHandler раз в сутки собирает «Сводку с пустоши» и потребляет строки.
 * WipeManifest: TRANSIENT (без player-связи).
 */
class BossKillAnnounceQueueModel extends Model
{
    protected $table            = 'boss_kill_announce_queue';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'boss_point_id',
        'boss_level',
        'biome_id',
        'status',
        'created_at',
    ];
}
