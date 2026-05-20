<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S25 (v0.51.205) — 4 craft tasks для faction-unique weapons (ADR-029).
 *
 * handler_key=generic_craft (как S16-S20); output_type='weapon' в recipe →
 * GenericCraftCompletionHandler::handleWeaponOutput → characters_weapons.
 * Длительности — fallback; override через GameSettings
 * `tier3.weapons.<snake>.craft_duration_hours` (соседняя seed-миграция).
 * Idempotent.
 */
class S25AddFactionWeaponTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tasks = [
            ['name' => 'craftBunkerRifle',          'name_rus' => 'Крафт: Бункерная винтовка (фракц.)',     'description' => 'Сборка военной винтовки из бункерного арсенала. ~5 часов.',  'min_duration' => 300, 'max_duration' => 300],
            ['name' => 'craftTechnoBeamShotgun',    'name_rus' => 'Крафт: Технолучевой дробовик (фракц.)',  'description' => 'Сборка доколлапсного коилгана. ~6 часов.',                   'min_duration' => 360, 'max_duration' => 360],
            ['name' => 'craftGhostCityKnife',       'name_rus' => 'Крафт: Нож Города-призрака (фракц.)',    'description' => 'Балансировка ножа контрабандистов. ~3 часа.',               'min_duration' => 180, 'max_duration' => 180],
            ['name' => 'craftFarmersHarvestScythe', 'name_rus' => 'Крафт: Коса Жатвы (фракц.)',             'description' => 'Перековка боевой косы. ~4 часа.',                            'min_duration' => 240, 'max_duration' => 240],
        ];

        foreach ($tasks as $taskRow) {
            $existing = $this->db->table('tasks')->where('name', $taskRow['name'])->get()->getRowArray();
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
                'difficulty_level'           => 5,
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
            ->whereIn('name', ['craftBunkerRifle', 'craftTechnoBeamShotgun', 'craftGhostCityKnife', 'craftFarmersHarvestScythe'])
            ->delete();
    }
}
