<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * web-accounts-p0-01 (ADR-188) — одноразовые коды из бота для входа на сайт
 * (в БД только sha256 нормализованного кода).
 */
class AccountLinkCodeModel extends Model
{
    protected $table            = 'account_link_codes';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'character_id', 'code_hash', 'expires_at', 'used_at', 'created_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
