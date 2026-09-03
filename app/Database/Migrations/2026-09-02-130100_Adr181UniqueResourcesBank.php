<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * exploit-fix-10 (ADR-181 §5) — F13: `ResourcesBankModel::first()` без `ORDER BY` берёт
 * произвольную строку, когда за один `resource_id` в `resources_bank` оказалось несколько строк
 * (гонка read-then-write между двумя почти одновременными транзакциями двух ресурсов). `UNIQUE
 * KEY(resource_id)` делает вторую строку физически невозможной.
 *
 * Замер (`docs/specs/exploit-fix/prod-measure.md`, story exploit-fix-03, Q6/Q7/Q8): 80 строк,
 * **0** дублей по `resource_id`. Слияние ниже написано как защитный, идемпотентный код на случай
 * гонки между замером и накаткой — по текущим данным он не исполнится ни разу.
 *
 * Стратегия слияния (ADR-181 §5): сохраняется строка с МИНИМАЛЬНЫМ `id` — та самая, которую
 * продовый `Model::first()` (`ORDER BY id ASC LIMIT 1` по умолчанию) и читал. Значения сирот
 * НЕ суммируются в выжившую строку (`current_quantity`/`resources_purchased`/`resources_sold`
 * сирот-дублей никогда не участвовали в ценообразовании — их внесение сдвинуло бы цену на
 * величину, которую никто не выбирал, ADR-175), только логируются на уровне `error` (на проде
 * INFO не хранится — сводка сирот на этом уровне не оставила бы следа) и удаляются.
 *
 * exploit-fix-19: слияние и `ALTER TABLE` идут под `LOCK TABLES resources_bank WRITE`, чтобы
 * вставка первого трейда в окно между `DELETE` дублей и `ALTER TABLE` не роняла ALTER и не
 * останавливала `post-deploy.sh`. `UNLOCK TABLES` — в `finally`, чтобы упавший ALTER не оставил
 * таблицу запертой.
 *
 * exploit-fix-30 (ревью m11): три правки. (1) Имя таблицы берётся один раз через
 * `$db->prefixTable('resources_bank')` и используется во всех запросах — раньше SQL был написан
 * с голым именем таблицы в обход табличного префикса CI4. (2) `ADD UNIQUE KEY` теперь идёт только
 * если индекс `uniq_resource_id` ещё не существует (проверка через
 * `information_schema.STATISTICS`) — повторный `up()` на таблице с уже применённым индексом
 * больше не падает на дубликате имени ключа. (3) Перед `LOCK TABLES` сессии выставляется
 * `lock_wait_timeout = 30`: без этого чужой metadata-лок на таблице заставил бы `LOCK TABLES`
 * ждать серверный дефолт (обычно порядка года), и `post-deploy.sh` завис бы вместо быстрого
 * падения. Исходное значение читается до `SET SESSION` и восстанавливается в `finally` —
 * независимо от того, что происходит внутри `LOCK TABLES`/`ALTER TABLE`.
 */
class Adr181UniqueResourcesBank extends Migration
{
    public function up()
    {
        $db    = \Config\Database::connect();
        $table = $db->prefixTable('resources_bank');

        $originalLockWaitTimeout = $db->query('SELECT @@SESSION.lock_wait_timeout AS v')->getRow()->v;

        $db->query('SET SESSION lock_wait_timeout = 30');

        try {
            $db->query('LOCK TABLES ' . $table . ' WRITE');

            try {
                // Защитное слияние — по замеру (Q7/Q8) не найдёт ни одной группы, но должно
                // пережить гонку между замером и накаткой без падения ALTER TABLE ниже.
                $dupGroups = $db->query(
                    'SELECT resource_id FROM ' . $table . ' GROUP BY resource_id HAVING COUNT(*) > 1'
                )->getResultArray();

                foreach ($dupGroups as $group) {
                    $resourceId = (int) $group['resource_id'];

                    $rows = $db->query(
                        'SELECT id, current_quantity, resources_purchased, resources_sold FROM ' . $table . ' WHERE resource_id = ? ORDER BY id ASC',
                        [$resourceId]
                    )->getResultArray();

                    if (count($rows) < 2) {
                        continue;
                    }

                    $keepId  = (int) $rows[0]['id'];
                    $orphans = array_slice($rows, 1);

                    log_message(
                        'error',
                        'ADR-181 resources_bank merge: resource_id={resource_id} kept id={keep_id}, orphans discarded (values NOT summed, F13/ADR-175: orphan counters never priced anything): {orphans}',
                        [
                            'resource_id' => $resourceId,
                            'keep_id'     => $keepId,
                            'orphans'     => json_encode(array_map(static function (array $orphan): array {
                                return [
                                    'orphan_id' => (int) $orphan['id'],
                                    'cq'        => $orphan['current_quantity'],
                                    'rp'        => $orphan['resources_purchased'],
                                    'rs'        => $orphan['resources_sold'],
                                ];
                            }, $orphans)),
                        ]
                    );

                    $orphanIds = array_map(static fn (array $orphan): int => (int) $orphan['id'], $orphans);
                    $db->query(
                        'DELETE FROM ' . $table . ' WHERE id IN (' . implode(',', $orphanIds) . ')'
                    );
                }

                $indexExists = (int) $db->query(
                    'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$table, 'uniq_resource_id']
                )->getRow()->c;

                if ($indexExists === 0) {
                    $db->query('ALTER TABLE ' . $table . ' ADD UNIQUE KEY uniq_resource_id (resource_id)');
                }
            } finally {
                $db->query('UNLOCK TABLES');
            }
        } finally {
            $db->query('SET SESSION lock_wait_timeout = ' . (int) $originalLockWaitTimeout);
        }
    }

    public function down()
    {
        $db    = \Config\Database::connect();
        $table = $db->prefixTable('resources_bank');

        $indexExists = (int) $db->query(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, 'uniq_resource_id']
        )->getRow()->c;

        if ($indexExists > 0) {
            $db->query('ALTER TABLE ' . $table . ' DROP INDEX uniq_resource_id');
        }
    }
}
