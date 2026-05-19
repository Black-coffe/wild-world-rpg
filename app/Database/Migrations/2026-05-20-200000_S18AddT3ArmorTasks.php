<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S18 (v0.51.200) — 4 T3 armor craft tasks (ADR-026 reusable, ROADMAP-CRAFT Фаза 4 3/5).
 *
 * Подключает 4 уже существующих outfits (seed legacy в `outfits` table:
 * TacticalArmorSuit L16 Epic 22 armor, ExoskeletonStrekoza L16 Epic 10 armor,
 * TitanPowerArmor L20 Legendary 30 armor, TeslaShardArmor L25 Legendary 27 armor)
 * к T3-крафту через ProfessionalWorkbench (предмет в crafted_items_log,
 * gate через recipe.required_crafted_items — pattern S17).
 *
 * Audit-first finding (2026-05-19): ROADMAP S18 предлагал INSERT новых
 * armor (PlatedArmor/KevlarPatchwork/TacticalVest/HardenedHelmet) — drift:
 * 20 outfits L0-L25 уже в БД (seed legacy), incl. Legendary L20-L25
 * (Titan/Tesla/Phantom/Juggernaut/Shadow). Реальный gap = 16 outfits без
 * craft option (lootable only). User pick (2026-05-19): Option B (Generic
 * extension + рецепты для existing).
 *
 * Длительности — live-tunable через GameSettings (parallel seed migration).
 *
 * Idempotent: skip если `tasks.name='craft<X>'` уже существует.
 */
class S18AddT3ArmorTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tasks = [
            [
                'name'         => 'craftTacticalArmorSuit',
                'name_rus'     => 'Крафт: Тактический бронекостюм (T3)',
                'description'  => 'Сборка балансного бронекостюма Epic-tier. Длительность 2 часа (override через tier3.armor.tactical_armor_suit.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
            [
                'name'         => 'craftExoskeletonStrekoza',
                'name_rus'     => 'Крафт: Экзоскелет «Стрекоза» (T3)',
                'description'  => 'Сборка лёгкого экзоскелета. Длительность 2 часа (override через tier3.armor.exoskeleton_strekoza.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
            [
                'name'         => 'craftTitanPowerArmor',
                'name_rus'     => 'Крафт: Силовая броня «Титан» (T3)',
                'description'  => 'Сборка тяжёлой силовой брони Legendary. Длительность 4 часа (override через tier3.armor.titan_power_armor.craft_duration_hours).',
                'min_duration' => 240,
                'max_duration' => 240,
            ],
            [
                'name'         => 'craftTeslaShardArmor',
                'name_rus'     => 'Крафт: Осколочный доспех «Тесла» (T3)',
                'description'  => 'Сборка электризованной осколочной брони Legendary. Длительность 6 часов (override через tier3.armor.tesla_shard_armor.craft_duration_hours).',
                'min_duration' => 360,
                'max_duration' => 360,
            ],
        ];

        foreach ($tasks as $taskRow) {
            $existing = $this->db->table('tasks')
                ->where('name', $taskRow['name'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('tasks')->insert([
                'name'                       => $taskRow['name'],
                'handler_key'                => 'generic_craft',
                'name_rus'                   => $taskRow['name_rus'],
                'description'                => $taskRow['description'],
                'min_duration'               => $taskRow['min_duration'],
                'max_duration'               => $taskRow['max_duration'],
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
        $this->db->table('tasks')
            ->whereIn('name', [
                'craftTacticalArmorSuit',
                'craftExoskeletonStrekoza',
                'craftTitanPowerArmor',
                'craftTeslaShardArmor',
            ])
            ->delete();
    }
}
