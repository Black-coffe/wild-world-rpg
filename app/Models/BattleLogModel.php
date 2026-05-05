<?php

namespace App\Models;

use App\Entities\BattleLogEntity;
use CodeIgniter\Model;

/**
 * F1.4.3 — Model returnType = BattleLogEntity. @method анотації потрібні бо
 * CI4 Model::find()/findAll() generic mixed без template propagation.
 *
 * @method BattleLogEntity|null find($id = null)
 * @method list<BattleLogEntity> findAll(?int $limit = null, int $offset = 0)
 * @method BattleLogEntity|null first()
 */
class BattleLogModel extends Model
{
    /**
     * @var string $table Название таблицы
     */
    protected $table = 'battle_logs';

    /**
     * @var string $primaryKey Имя поля - первичного ключа
     */
    protected $primaryKey = 'id';

    /**
     * @var string $returnType Формат возвращаемых данных
     * F1.4.3 (v0.46.0) — типизованная Entity замість 'array'
     */
    protected $returnType = BattleLogEntity::class;

    /**
     * @var bool $useTimestamps Нужно ли автоматически вести поля created_at/updated_at
     * Если хотим сами управлять 'created_at' и 'finished_at', лучше оставить false.
     */
    protected $useTimestamps = false;

    /**
     * @var array $allowedFields Разрешённые к заполнению/изменению поля
     */
    protected $allowedFields = [
        'battle_type',
        'player1_id',
        'player2_id',
        'winner_id',
        'created_at',
        'finished_at',
        'log_data',
    ];
}
