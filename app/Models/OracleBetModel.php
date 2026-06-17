<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-133 — ставки игроков в «Оракуле острова». PLAYER_DATA (link character_id, WipeManifest).
 * Золото эскроу-дебетуется при размещении (status='placed'); на расчёте → won|lost|refunded.
 */
class OracleBetModel extends Model
{
    protected $table         = 'oracle_bets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    /** @var list<string> */
    protected $allowedFields = [
        'market_id',
        'character_id',
        'outcome_code',
        'stake',
        'status',
        'payout',
        'created_at',
        'resolved_at',
    ];
}
