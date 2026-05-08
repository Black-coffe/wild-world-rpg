<?php

namespace App\Entities;

use App\Entities\Traits\ArrayAccessibleEntity;
use ArrayAccess;
use CodeIgniter\Entity\Entity;
use CodeIgniter\I18n\Time;

/**
 * F1.4.4 Step B — типизированная Entity для таблицы characters (HOT path, ~300 callers).
 *
 * Совместимость: использует {@see ArrayAccessibleEntity} trait — старый код
 * `$character['name']` продолжает работать через __get/__set делегирование,
 * новый код может использовать typed access `$character->name`.
 *
 * @property int $id
 * @property int|null $telegram_user_id
 * @property string|null $name
 * @property int $level
 * @property float $experience
 * @property float $health
 * @property float|null $tired
 * @property float $strength
 * @property float $agility
 * @property float $intellect
 * @property int|null $gold
 * @property int|null $cell_number
 * @property int|null $biome_id
 * @property float $trading_karma
 * @property bool $insurance
 * @property string|null $preferred_map_type
 * @property bool $has_renamed
 * @property Time|null $last_name_change
 * @property Time|null $low_health_notified_at
 * @property int|null $last_message_id
 * @property Time|null $last_update_time
 * @property string $endgame_state
 * @property Time|null $endgame_lock_at
 * @property Time|null $created_at
 * @property Time|null $updated_at
 *
 * @implements ArrayAccess<mixed, mixed>
 */
class CharacterEntity extends Entity implements ArrayAccess
{
    use ArrayAccessibleEntity;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id'                  => 'integer',
        'telegram_user_id'    => '?integer',
        'level'               => 'integer',
        'experience'          => 'float',
        'health'              => 'float',
        'tired'               => '?float',
        'strength'            => 'float',
        'agility'             => 'float',
        'intellect'           => 'float',
        'gold'                => '?integer',
        'cell_number'         => '?integer',
        'biome_id'            => '?integer',
        'trading_karma'       => 'float',
        'insurance'           => 'boolean',
        'has_renamed'         => 'boolean',
        'last_message_id'     => '?integer',
        'endgame_state'       => 'string',
    ];

    /**
     * @var list<string>
     */
    protected $dates = [
        'created_at',
        'updated_at',
        'last_name_change',
        'low_health_notified_at',
        'endgame_lock_at',
        'last_update_time',
    ];
}
