<?php

namespace App\Models;

use CodeIgniter\Model;

class NpcSpawnModel extends Model
{
    /**
     * Название таблицы в базе данных.
     *
     * @var string
     */
    protected $table = 'npc_spawns';

    /**
     * Первичный ключ таблицы.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Использовать автоинкремент для PK?
     *
     * @var bool
     */
    protected $useAutoIncrement = true;

    /**
     * Формат возвращаемых данных: 'array' или 'object'.
     *
     * @var string
     */
    protected $returnType = 'array';

    /**
     * Использовать мягкое удаление (soft delete)?
     *
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Поля, разрешённые для массового заполнения (insert, update).
     *
     * @var array
     */
    protected $allowedFields = [
        'npc_id',
        'cell_number',
        'coordinate_x',
        'coordinate_y',
        'current_health',
        'spawned_at',
        'status',
    ];

    /**
     * Если хотим автоматически проставлять поля created_at / updated_at —
     * ставим $useTimestamps = true и задаём $createdField / $updatedField.
     * Но в данном случае спавн и статус NPC обычно управляются вручную.
     *
     * @var bool
     */
    protected $useTimestamps = false;

    // Если захотите включить валидацию:
    protected $validationRules = [
        'npc_id'        => 'required|integer',
        'cell_number'   => 'required|integer',
        'coordinate_x'  => 'required|integer',
        'coordinate_y'  => 'required|integer',
        'current_health'=> 'required|decimal',
        'status'        => 'required|string|max_length[20]',
    ];

    protected $validationMessages = [
        'npc_id' => [
            'required' => 'Поле npc_id обязательно.',
            'integer'  => 'npc_id должно быть целым числом.',
        ],
        'current_health' => [
            'decimal'  => 'current_health должно быть в формате decimal (7,2).',
        ],
    ];

    /**
     * Нужно ли пропускать валидацию при insert / update?
     */
    protected $skipValidation = false;

    // Здесь можно добавить дополнительные методы,
    // например, для нахождения всех живых NPC или убитых и т.д.

    /**
     * Найти все активные (alive) спавны NPC.
     *
     * @return array
     */
    public function getAllAliveSpawns(): array
    {
        return $this->where('status', 'alive')->findAll();
    }

    /**
     * Пометить спавн как убитый (dead) и вернуть кол-во затронутых строк.
     *
     * @param int $spawnId
     * @return int
     */
    public function markAsDead(int $spawnId): int
    {
        return $this->update($spawnId, [
            'status' => 'dead',
        ]);
    }
}
