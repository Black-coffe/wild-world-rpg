<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB4 (ADR-137 «Узлы») — таблица фиксированных точек-узлов (мировые боссы).
 *
 * Узел = постоянная точка территории. `current_level` = сколько раз точку расчищали
 * (память точки, не существа). Гео-эскалация: `base_level` = f(`y_band`) — юг низкий,
 * север высокий. Респавн/эскалацию ведёт `NodeRespawnHandler` (WB5); бой инициирует
 * игрок через encounter (WB6). Точки наполняются seed-миграцией WB4 (24 шт).
 *
 * `current_health` — INT, НЕ FLOAT (точность на больших HP верхних тиров — урок §4 ADR).
 *
 * WipeManifest (ADR-087): boss_points = **SEED_RESET** — строки-точки остаются (мировой
 * контент), но прогресс обнуляется к base (current_level→base_level, current_health→
 * max_health, kill_count→0, status→alive). Точки снова L1 для новичков → сезонность.
 * Классификация добавляется в Config\WipeManifest этой же сессией.
 */
class Adr137CreateBossPoints extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'cell_number'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true], // FK map.cell_number (курируемая)
            'coordinate_x'             => ['type' => 'INT', 'constraint' => 11],
            'coordinate_y'             => ['type' => 'INT', 'constraint' => 11],
            'biome_id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'y_band'                   => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true], // широтная полоса → старт-уровень
            'base_level'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'current_level'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'current_health'           => ['type' => 'INT', 'constraint' => 11], // НЕ FLOAT
            'max_health'               => ['type' => 'INT', 'constraint' => 11],
            'status'                   => ['type' => 'ENUM', 'constraint' => ['alive', 'cooldown'], 'default' => 'alive'],
            'respawn_at'               => ['type' => 'DATETIME', 'null' => true], // когда поднимется (НЕ updated_at)
            'last_killer_character_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'kill_count'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'current_npc_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true], // FK npcs (шаблон-архетип)
            'created_at'               => ['type' => 'DATETIME', 'null' => true],
            'updated_at'               => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('cell_number'); // одна точка на клетку
        $this->forge->addKey(['status', 'respawn_at']);
        $this->forge->createTable('boss_points', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('boss_points', true);
    }
}
