<?php

namespace App\Models;

use CodeIgniter\Model;

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
     * @var string $returnType Формат возвращаемых данных (array|object)
     */
    protected $returnType = 'array';

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
