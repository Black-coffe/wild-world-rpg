<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * W9 (ADR-066) — справочник достижений. Контент-таблица (медали добавляются строкой).
 * Читается {@see \App\Services\Player\AchievementService}.
 */
class AchievementModel extends Model
{
    protected $table          = 'achievements';
    protected $primaryKey     = 'id';
    protected $useAutoIncrement = true;
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'achievement_key', 'title', 'description', 'category', 'icon',
        'criteria_type', 'criteria_target', 'points', 'sort_order', 'enabled',
        'created_at', 'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
