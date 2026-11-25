<?php

declare(strict_types=1);

namespace App\Services\Craft;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;

/**
 * craft-queue-promote-1125 S2b — страховочный проход очереди крафта: персонажи, чью очередь
 * {@see CraftQueueService::promoteEligible()} может запустить прямо сейчас, хотя ни одна задача из
 * работы не вышла (выход, которого хуки не знают: смерть, админка, будущий код). Worker зовёт его раз
 * за тик; задержка таких путей — один тик.
 *
 * Отбор повторяет правило годности `promoteEligible()` и добавляет свободный слот: `queued`-крафт, у
 * рецепта (`task_id`) которого нет `in_work`-строки этого персонажа, и строка либо ⏳ (`is_exclusive=0`),
 * либо 🔒 при свободном 🔒-слоте. Персонаж, чей 🔒-крафт всё равно откажут, в выборку не попадает и
 * предел тика не съедает. Только чтение; запуск и атомарность — `promoteEligible()`.
 */
class CraftQueueSweep
{
    /** @param BaseConnection<object, object>|null $db */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * До `$limit` персонажей с застрявшей очередью, старшая застрявшая строка первой.
     *
     * @return list<int>
     */
    public function strandedCharacterIds(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $db     = $this->db ?? Database::connect();
        $ct     = $db->prefixTable('character_tasks');
        $tasks  = $db->prefixTable('tasks');
        $result = $db->query(
            "SELECT q.character_id, MIN(q.id) AS head FROM {$ct} q INNER JOIN {$tasks} t ON t.id = q.task_id"
            . " WHERE q.status = 'queued' AND t.type = 'craft'"
            . " AND NOT EXISTS (SELECT 1 FROM {$ct} w WHERE w.character_id = q.character_id AND w.task_id = q.task_id AND w.status = 'in_work')"
            . " AND (q.is_exclusive = 0 OR NOT EXISTS (SELECT 1 FROM {$ct} x WHERE x.character_id = q.character_id AND x.status = 'in_work' AND x.is_exclusive = 1))"
            . ' GROUP BY q.character_id ORDER BY head ASC LIMIT ' . $limit
        );
        $rows = $result instanceof BaseResult ? $result->getResultArray() : [];

        $ids = [];
        foreach ($rows as $row) {
            $id = is_numeric($row['character_id'] ?? null) ? (int) $row['character_id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
