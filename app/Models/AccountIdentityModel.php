<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * web-accounts-p0-01 (ADR-188) — способ входа аккаунта: email / google / yandex / telegram.
 * UNIQUE(provider, subject). Операции — {@see \App\Services\Web\AccountService}.
 */
class AccountIdentityModel extends Model
{
    protected $table            = 'account_identities';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'account_id', 'provider', 'subject', 'secret_hash', 'email', 'created_at', 'last_used_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
