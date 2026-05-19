<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S16 (v0.51.198) — Tier 3 Professional Workbench (ADR-026, ROADMAP-CRAFT Фаза 4).
 *
 * Создаёт два DB-объекта:
 *   1. `crafted_items` row `ProfessionalWorkbench` (type='workbench').
 *   2. `tasks` row `craftProfessionalWorkbench` (min/max_duration = 480 min = 8h
 *      fallback; в проде значение overrides через GameSettings ключ
 *      `tier3.workbench.craft_duration_hours`, см. parallel migration
 *      `2026-05-19-900000_S16SeedProfessionalWorkbenchGameSettings.php`).
 *
 * Канон-расширение [[ADR-026]]: до S16 был «двухуровневый» крафт-stack
 * (Workbench General + Workbench Standard). T3 = новый тир, открывает T3
 * рецепты (S17-S20).
 *
 * Idempotent: skip если `crafted_items.name_eng='ProfessionalWorkbench'`
 * или `tasks.name='craftProfessionalWorkbench'` уже существует.
 */
class S16AddProfessionalWorkbench extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $existingItem = $this->db->table('crafted_items')
            ->where('name_eng', 'ProfessionalWorkbench')
            ->get()
            ->getRowArray();
        if (empty($existingItem)) {
            $this->db->table('crafted_items')->insert([
                'name_rus'          => 'Профессиональный верстак',
                'name_eng'          => 'ProfessionalWorkbench',
                'type'              => 'workbench',
                'direction_craft'   => 'workbench',
                'effect'            => 'Открывает крафт предметов Tier 3 (требует уровень 20)',
                'durability_count'  => 200,
                'durability_time'   => null,
                'damage'            => 0,
                'armor'             => 0,
                'hp'                => 250,
                'character_boost'   => null,
                'weight'            => 350,
                'price'             => 150000,
                'stack_size'        => 1,
                'required_level'    => 20,
                'required_skills'   => null,
                'required_resources' => null,
                'crafting_time'     => '480', // 8h fallback (canonical source: GameSettings tier3.workbench.craft_duration_hours)
                'crafting_location' => 'Workbench Professional',
                'description'      => 'Профессиональный верстак — третий тир мастерской. Тяжёлый, ручной сборки, с парными тисками, маленьким горном и стеной для инструментов. Открывает рецепты Tier 3 (оружие, броня, медицина, утилиты). Требует уровня 20, развитой базы (BlastFurnace и Laboratory) и редких ресурсов (доколлапсная электроника, промышленный пластик).',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        $existingTask = $this->db->table('tasks')
            ->where('name', 'craftProfessionalWorkbench')
            ->get()
            ->getRowArray();
        if (empty($existingTask)) {
            $this->db->table('tasks')->insert([
                'name'                       => 'craftProfessionalWorkbench',
                'handler_key'                => 'generic_craft',
                'name_rus'                   => 'Крафт: Профессиональный верстак',
                'description'                => 'Создание Tier 3 верстака. Длительность 8 часов (override через GameSettings ключ tier3.workbench.craft_duration_hours).',
                'min_duration'               => 480,
                'max_duration'               => 480,
                'type'                       => 'craft',
                'difficulty_level'           => 4,
                'execution_limit'            => 0,
                'parallel_execution_allowed' => 0,
                'interruptible'              => 1,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')->where('name', 'craftProfessionalWorkbench')->delete();
        $this->db->table('crafted_items')->where('name_eng', 'ProfessionalWorkbench')->delete();
    }
}
