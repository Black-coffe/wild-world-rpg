<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * W9 (ADR-066) — выданные достижения персонажей. UNIQUE(character_id, achievement_id)
 * на уровне БД = идемпотентность выдачи. unlocked_at ставится вручную при выдаче.
 */
class CharacterAchievementModel extends Model
{
    protected $table          = 'character_achievements';
    protected $primaryKey     = 'id';
    protected $useAutoIncrement = true;
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['character_id', 'achievement_id', 'unlocked_at'];

    protected $useTimestamps = false;
}
