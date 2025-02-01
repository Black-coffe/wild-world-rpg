<?php

namespace App\Models;

use CodeIgniter\Model;

class TeleportBeaconModel extends Model
{
    /**
     * @var string $table Имя таблицы в БД
     */
    protected $table = 'teleport_beacons';

    /**
     * @var string $primaryKey Первичный ключ
     */
    protected $primaryKey = 'id';

    /**
     * @var string $returnType Тип данных возвращаемый при запросах (array|object)
     */
    protected $returnType = 'array';

    /**
     * @var bool $useSoftDeletes Использовать "мягкое удаление" (soft delete) или нет
     */
    protected $useSoftDeletes = false;

    /**
     * @var array $allowedFields Список полей, разрешённых к массовому присвоению
     */
    protected $allowedFields = [
        'character_id',
        'faction_id',
        'player_level_at_creation',
        'map_cell_id',
        'coordinate_x',
        'coordinate_y',
        'tax_cost',
        'remaining_uses',
        'last_teleport_at',
        'ownership_type',
        'settings_json',
        'created_at',
        'updated_at'
    ];

    /**
     * Метки времени (created_at, updated_at) будут автоматически обновляться моделью
     * при создании/обновлении записей, если установить данное свойство = true.
     */
    protected $useTimestamps = true;

    /**
     * Названия полей в таблице, отвечающих за время создания и обновления записи.
     */
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Если нужно указывать формат даты/времени для полей
     */
    protected $dateFormat = 'datetime';

    /**
     * Правила валидации (опционально, если вам нужна валидация в модели)
     */
    protected $validationRules = [
        'character_id'              => 'required|integer',
        'faction_id'                => 'permit_empty|integer',
        'player_level_at_creation'  => 'required|integer',
        'map_cell_id'               => 'required|integer',
        'coordinate_x'              => 'required|integer',
        'coordinate_y'              => 'required|integer',
        'tax_cost'                  => 'required|integer',
        'remaining_uses'            => 'required|integer',
        'last_teleport_at'          => 'permit_empty|valid_date',
        'ownership_type'            => 'required|in_list[author,captured]',
        'settings_json'             => 'permit_empty'
    ];

    /**
     * Сообщения валидации (опционально).
     */
    protected $validationMessages = [
        'ownership_type' => [
            'in_list' => 'ownership_type must be either "author" or "captured".'
        ]
    ];

    /**
     * Пропускать ли валидацию (по умолчанию false).
     */
    protected $skipValidation = false;

    // При необходимости можно добавить дополнительные методы
    // Например, для поиска маяков конкретного игрока и т.д.
    // public function getBeaconsByCharacter(int $charId) { ... }
}
