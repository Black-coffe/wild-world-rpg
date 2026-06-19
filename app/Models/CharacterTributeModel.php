<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-135 «Трофейная подать» — отношения дани между игроками (PvP-доминирование).
 *
 * Одна строка = одно отношение master→vassal (активное или историческое).
 * Hot-path выборки идут по индексам (vassal_id,status) / (master_id,status) /
 * expires_at (миграция 2026-08-21-100000_Adr135CreateCharacterTributes).
 */
class CharacterTributeModel extends Model
{
    protected $table         = 'character_tributes';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false; // started_at/expires_at/created_at/updated_at ставим явно

    /** @var list<string> */
    protected $allowedFields = [
        'master_id',
        'vassal_id',
        'rate',
        'status',
        'total_collected',
        'collected_today',
        'collected_today_date',
        'started_at',
        'expires_at',
        'lifted_by',
        'lifted_at',
        'created_at',
        'updated_at',
    ];
}
