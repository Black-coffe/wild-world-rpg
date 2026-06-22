<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * WB10 (ADR-137 «Узлы») — анти-кемп dwell-состояние игрока у узла.
 *
 * Одна строка на (character_id, boss_point_id) — UNIQUE. `dwell_minutes` копит online-минуты
 * «торчания» в радиусе узла; при превышении `world.nodes.anticamp.dwell_hours` ставится
 * `loot_locked_until` (kill даёт 0 трофеев + не killer для анонса). Строка живёт, пока перс в
 * радиусе; уход из зоны → удаление (сброс). Ведёт AntiCampDwellHandler (WB10), читает
 * AntiCampService (kill-путь). WipeManifest: PLAYER_DATA.
 */
class BossCampStateModel extends Model
{
    protected $table            = 'boss_camp_state';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'character_id',
        'boss_point_id',
        'first_seen_in_zone_at',
        'dwell_minutes',
        'loot_locked_until',
        'last_warned_at',
    ];
}
