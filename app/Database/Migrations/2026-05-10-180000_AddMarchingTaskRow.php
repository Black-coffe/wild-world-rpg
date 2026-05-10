<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-019 Step 3 — строка `Marching` в таблице `tasks`.
 *
 * `Worker.php` сопоставляет `tasks.name` → handler-классу (`taskHandlerMap`):
 * `'Marching' => 'MarchingTaskHandler'`. `MarchAction` создаёт `character_tasks`
 * с этим `task_id`; `MarchingTaskHandler` продвигает марш на 1 клетку за тик и
 * спавнит следующую `Marching`-задачу (chain) либо завершает/паузит поход.
 *
 * `parallel_execution_allowed = 0` — марш блокирует/блокируется другими
 * задачами (как стоячий `ExploreTheArea`). `interruptible = 1` — можно отменить
 * (`cancelMarch`), пройденное сохраняется. Длительность не фиксирована —
 * считается из числа шагов и пишется в `character_tasks.end_time`, поэтому
 * `min_duration`/`max_duration` = 0.
 *
 * Идемпотентно: insert-if-not-exists. down() — удалить строку.
 */
class AddMarchingTaskRow extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        $exists = $db->table('tasks')->where('name', 'Marching')->countAllResults() > 0;
        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('tasks')->insert([
            'name'                       => 'Marching',
            'name_rus'                   => 'Поход',
            'description'                => 'Направленный многоклеточный переход. Карта раскрывается по мере движения (туман войны, ADR-019). Поход прерывается на любой точке-решении (встреча с игроком, тяжёлая рана, чужой лагерь на пути, край мира, привал по усталости).',
            'min_duration'               => 0,
            'max_duration'               => 0,
            'type'                       => 'exploration',
            'difficulty_level'           => 1,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 0,
            'interruptible'              => 1,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->table('tasks')->where('name', 'Marching')->delete();
    }
}
