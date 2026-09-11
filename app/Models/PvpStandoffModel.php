<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-186 (pvp-detection-clarity-06) — окно противостояния перед полевой PvP-атакой.
 * Владелец записи и чтения — `App\Services\PVE\PvpStandoffService`, он же держит
 * условную вставку (`insertUnique()`) и переход статуса (`transitionIfCurrent()`) —
 * эта модель не пишет строки напрямую там, где нужна гонко-безопасная запись.
 *
 * `$allowedFields` НЕ включает `open_defender_id` — это STORED-генерируемая колонка
 * (`IF(status='open', defender_id, NULL)`), запись в неё MySQL отвергает.
 */
class PvpStandoffModel extends Model
{
    protected $table      = 'pvp_standoffs';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'attacker_id',
        'defender_id',
        'cell_number',
        'started_at',
        'expires_at',
        'status',
        'notified_expired',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'attacker_id' => 'required|integer',
        'defender_id' => 'required|integer',
        'cell_number' => 'required|integer',
        'status'      => 'required|in_list[open,fled,countered,held,expired,cancelled]',
    ];
}
