<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * WB6 (ADR-137 «Узлы») — активный многоходовый бой игрока с узлом-боссом.
 *
 * Один active-бой на персонажа; HP узла персистентен в boss_points, HP игрока — `player_hp`.
 * Ведёт BossEncounterService (старт/чанк-раунды/исход), GC-крон гасит зависшие active → fled.
 * WipeManifest: PLAYER_DATA (by character).
 */
class BossEncounterModel extends Model
{
    protected $table            = 'boss_encounters';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false; // created_at ставим вручную, updated_at нет

    protected $allowedFields = [
        'boss_point_id',
        'character_id',
        'round_no',
        'player_hp',
        'status',
        'damage_dealt',
        'last_special_round_no',
        'last_action_at',
        'created_at',
    ];
}
