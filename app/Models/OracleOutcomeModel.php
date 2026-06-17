<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-133 — исходы рынка «Оракул острова» (фракции / yes-no). TRANSIENT (WipeManifest).
 */
class OracleOutcomeModel extends Model
{
    protected $table         = 'oracle_outcomes';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    /** @var list<string> */
    protected $allowedFields = [
        'market_id',
        'code',
        'label',
        'sort_order',
    ];
}
