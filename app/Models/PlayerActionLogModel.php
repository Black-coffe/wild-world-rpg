<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * ADR-148 — модель firehose всех прямых действий игрока (таблица `player_action_log`).
 *
 * Append-only телеметрия: пишется централизованно из {@see \App\Services\Logging\PlayerActionLogger}.
 * `created_at` проставляется явно сервисом (useTimestamps=false) — нет `updated_at`, строки не
 * обновляются. Отдельна от {@see ActionLogModel} (курируемые маркеры онбординга/квестов/воронки).
 *
 * @see \App\Services\Logging\PlayerActionLogger
 */
class PlayerActionLogModel extends Model
{
    protected $table         = 'player_action_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps  = false; // created_at — вручную в сервисе; updated_at отсутствует

    protected $allowedFields = [
        'character_id',
        'telegram_user_id',
        'chat_id',
        'source',
        'action_name',
        'raw_input',
        'status',
        'error_text',
        'origin', // ADR-168 — с какого экрана нажата кнопка (метка `~cmp` на callback_data)
        'created_at',
    ];
}
