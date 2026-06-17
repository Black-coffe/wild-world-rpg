<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-133 — экземпляры рынков «Оракул острова» (пари-мютюэль). TRANSIENT (WipeManifest).
 * Один ряд на (market_key, cycle). Жизненный цикл: open → locked → settled|void.
 */
class OracleMarketModel extends Model
{
    protected $table         = 'oracle_markets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    /** @var list<string> */
    protected $allowedFields = [
        'market_key',
        'market_type',
        'question',
        'metric_key',
        'cycle',
        'status',
        'rake_pct',
        'snapshot_start',
        'snapshot_final',
        'winning_outcome',
        'opens_at',
        'locks_at',
        'resolves_at',
        'created_at',
        'updated_at',
    ];
}
