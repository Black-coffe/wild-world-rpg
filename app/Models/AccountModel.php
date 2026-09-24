<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * web-accounts-p0-01 (ADR-188) — аккаунт игрока: корень, которому принадлежат персонаж и
 * способы входа. Операции — {@see \App\Services\Web\AccountService}.
 */
class AccountModel extends Model
{
    protected $table            = 'accounts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'acquisition_source', 'last_login_at', 'created_at', 'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
