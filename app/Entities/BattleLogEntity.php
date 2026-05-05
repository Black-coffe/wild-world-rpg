<?php

namespace App\Entities;

use App\Entities\Traits\ArrayAccessibleEntity;
use ArrayAccess;
use CodeIgniter\Entity\Entity;
use CodeIgniter\I18n\Time;

/**
 * F1.4.3 — типизированная Entity для таблицы battle_logs.
 *
 * Поле log_data хранит JSON-encoded історію боя (раундовий лог,
 * метаданные attacker/target). Декодується на read-side через
 * json_decode у callers (BattlesController::view).
 *
 * @property int $id
 * @property string $battle_type
 * @property int|null $player1_id
 * @property int|null $player2_id
 * @property int|null $winner_id
 * @property string $log_data
 * @property Time|null $created_at
 * @property Time|null $finished_at
 *
 * @implements ArrayAccess<mixed, mixed>
 */
class BattleLogEntity extends Entity implements ArrayAccess
{
    use ArrayAccessibleEntity;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id'         => 'integer',
        'player1_id' => '?integer',
        'player2_id' => '?integer',
        'winner_id'  => '?integer',
    ];

    /**
     * @var list<string>
     */
    protected $dates = ['created_at', 'finished_at'];
}
