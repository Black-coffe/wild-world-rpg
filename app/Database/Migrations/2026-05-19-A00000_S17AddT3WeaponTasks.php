<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S17 (v0.51.199) — 5 T3 weapon craft tasks (ADR-026, ROADMAP-CRAFT Фаза 4).
 *
 * Подключает 5 уже существующих weapons (seed legacy: GaussPistol L16,
 * RailCarbineVikhr L18, IonDestabilizer L20, FlamethrowerAid L20,
 * ExoRailgunBehemoth L24) к T3-крафту через ProfessionalWorkbench
 * (предмет в crafted_items_log, gate через recipe.required_crafted_items).
 *
 * Audit-first finding (2026-05-19): ROADMAP S17 предлагал INSERT новых
 * weapon rows (junk-tier MasterCrossbow/HeavyRebar/...) — drift: на L20+
 * уже стоят Legendary energy weapons (4 шт. L20-L26). Реальный gap —
 * 16 lootable weapons L4-L26 без craft option. User pick: Option B —
 * рецепты для existing weapons (см. memory feedback_s17_drift_weapons_already_in_db.md).
 *
 * Длительности (fallback для tasks.min/max_duration): GameSettings
 * `tier3.weapons.<name>.craft_duration_hours` overrides через recipe
 * field duration_override_setting_key (см. parallel seed migration).
 *
 * Idempotent: skip если `tasks.name='craft<X>'` уже существует.
 */
class S17AddT3WeaponTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tasks = [
            [
                'name'         => 'craftGaussPistol',
                'name_rus'     => 'Крафт: Гаусс-пистолет (T3)',
                'description'  => 'Сборка магнито-импульсного пистолета. Длительность 2 часа (override через tier3.weapons.gauss_pistol.craft_duration_hours).',
                'min_duration' => 120,
                'max_duration' => 120,
            ],
            [
                'name'         => 'craftRailCarbineVikhr',
                'name_rus'     => 'Крафт: Рельсотрон-карабин «Вихрь» (T3)',
                'description'  => 'Сборка компактного рельсотрона. Длительность 3 часа (override через tier3.weapons.rail_carbine_vikhr.craft_duration_hours).',
                'min_duration' => 180,
                'max_duration' => 180,
            ],
            [
                'name'         => 'craftIonDestabilizer',
                'name_rus'     => 'Крафт: Ион-дестабилизатор (T3)',
                'description'  => 'Сборка ионного дестабилизатора. Длительность 4 часа (override через tier3.weapons.ion_destabilizer.craft_duration_hours).',
                'min_duration' => 240,
                'max_duration' => 240,
            ],
            [
                'name'         => 'craftFlamethrowerAid',
                'name_rus'     => 'Крафт: Огнемёт-«Помощь» (T3)',
                'description'  => 'Сборка ранцевого огнемёта. Длительность 4 часа (override через tier3.weapons.flamethrower_aid.craft_duration_hours).',
                'min_duration' => 240,
                'max_duration' => 240,
            ],
            [
                'name'         => 'craftExoRailgunBehemoth',
                'name_rus'     => 'Крафт: Экзо-рельсотрон «Бегемот» (T3)',
                'description'  => 'Сборка тяжёлого носимого рельсотрона. Длительность 6 часов (override через tier3.weapons.exo_railgun_behemoth.craft_duration_hours).',
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
                'craftGaussPistol',
                'craftRailCarbineVikhr',
                'craftIonDestabilizer',
                'craftFlamethrowerAid',
                'craftExoRailgunBehemoth',
            ])
            ->delete();
    }
}
