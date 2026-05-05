<?php

namespace App\Entities;

use App\Entities\Traits\ArrayAccessibleEntity;
use ArrayAccess;
use CodeIgniter\Entity\Entity;
use CodeIgniter\I18n\Time;

/**
 * F1.4.2 — типизированная Entity для таблицы resources (~70 строк, справочник).
 *
 * Поля biome_id и type хранятся как JSON-строки (см. миграцию
 * ModifyResourcesTable.php) — отсюда string|null а не int|array.
 *
 * @property int $id
 * @property string $name
 * @property string $name_en
 * @property string|null $description
 * @property string|null $biome_id
 * @property bool $is_tradeable
 * @property string $type
 * @property int $price
 * @property float|null $buy_price
 * @property float|null $sell_price
 * @property int $rarity
 * @property int $level_required
 * @property int $initial_quantity
 * @property string|null $keyword
 * @property string|null $icon_text
 * @property Time|null $created_at
 * @property Time|null $updated_at
 *
 * @implements ArrayAccess<mixed, mixed>
 */
class ResourceEntity extends Entity implements ArrayAccess
{
    use ArrayAccessibleEntity;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id'               => 'integer',
        'is_tradeable'     => 'boolean',
        'price'            => 'integer',
        'buy_price'        => '?float',
        'sell_price'       => '?float',
        'rarity'           => 'integer',
        'level_required'   => 'integer',
        'initial_quantity' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $dates = ['created_at', 'updated_at'];
}
