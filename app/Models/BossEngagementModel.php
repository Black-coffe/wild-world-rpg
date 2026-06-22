<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * WB8 (ADR-137 «Узлы») — ledger вклада «Облавы» (co-op по урону).
 *
 * Одна строка = суммарный урон (character) по (boss_point, boss_level) за текущую жизнь узла.
 * Апсёрт/инкремент ведёт {@see \App\Services\PVE\BossLootService::recordContribution} напрямую
 * (raw INSERT … ON DUPLICATE KEY UPDATE — атомарно). При килле distributeLoot делит лут по вкладу
 * и удаляет потреблённые строки; GC чистит недобитые по last_hit_at. WipeManifest: PLAYER_DATA.
 */
class BossEngagementModel extends Model
{
    protected $table            = 'boss_engagements';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'boss_point_id',
        'boss_level',
        'character_id',
        'damage_dealt',
        'rounds_participated',
        'first_hit_at',
        'last_hit_at',
    ];
}
