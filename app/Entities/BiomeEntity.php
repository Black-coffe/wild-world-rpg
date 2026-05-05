<?php

namespace App\Entities;

use App\Entities\Traits\ArrayAccessibleEntity;
use ArrayAccess;
use CodeIgniter\Entity\Entity;
use CodeIgniter\I18n\Time;

/**
 * F1.4.1 — типизированная Entity для таблицы biomes (9 строк, справочник).
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $biome_type
 * @property string|null $danger_level_text
 * @property int|null $danger_level
 * @property string|null $survival_difficulty_text
 * @property int|null $survival_difficulty
 * @property float $occurrence_rate
 * @property Time|null $created_at
 * @property Time|null $updated_at
 *
 * @implements ArrayAccess<mixed, mixed>
 */
class BiomeEntity extends Entity implements ArrayAccess
{
    use ArrayAccessibleEntity;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id'                       => 'integer',
        'danger_level'             => '?integer',
        'survival_difficulty'      => '?integer',
        'occurrence_rate'          => 'float',
    ];

    /**
     * @var list<string>
     */
    protected $dates = ['created_at', 'updated_at'];
}
