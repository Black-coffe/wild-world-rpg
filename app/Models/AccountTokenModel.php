<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * web-accounts-p0-01 (ADR-188) — токены аккаунта: remember-me и сброс пароля
 * (selector + sha256 validator, в БД только хэш).
 */
class AccountTokenModel extends Model
{
    protected $table            = 'account_tokens';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'account_id', 'purpose', 'selector', 'validator_hash', 'expires_at', 'created_at', 'last_used_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
