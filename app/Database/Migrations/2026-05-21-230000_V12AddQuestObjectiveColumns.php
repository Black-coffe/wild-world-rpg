<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V12 (ROADMAP-CRAFT-vNext, Фаза 3, ADR-037) — data-driven quest-objective движок.
 *
 * `quests.objective_type` — что нужно сделать для завершения квеста:
 *   - discover_object  (objective_target = name_en стратегического объекта; завершается
 *     в StrategicLootHandler при обнаружении — НЕ через per-minute scan)
 *   - craft_item       (objective_target = name_eng предмета; objective_qty штук)
 *   - explore_cells    (objective_qty клеток в explored_cells)
 *   - char_level       (objective_qty — достичь уровня)
 * `objective_target` — цель (name_en/name_eng), null для explore/level.
 * `objective_qty` — порог (кол-во/уровень), default 1.
 *
 * null objective_type = старые bespoke-квесты (Explore30 и т.п.) — их трогает свой
 * per-quest handler, генерик их не касается (0 регрессии).
 */
class V12AddQuestObjectiveColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('quests', [
            'objective_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
                'comment'    => 'V12 (ADR-037): discover_object|craft_item|explore_cells|char_level. null = bespoke-квест.',
            ],
            'objective_target' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'comment'    => 'V12: цель (name_en объекта / name_eng предмета). null для explore/level.',
            ],
            'objective_qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => 1,
                'comment'    => 'V12: порог (кол-во предметов / клеток / уровень).',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('quests', ['objective_type', 'objective_target', 'objective_qty']);
    }
}
