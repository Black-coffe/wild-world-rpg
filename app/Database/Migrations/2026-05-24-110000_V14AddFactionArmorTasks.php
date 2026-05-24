<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V14 (ADR-046) — 4 craft tasks для faction-unique armor. Сиблинг S25 tasks.
 *
 * handler_key=generic_craft; output_type='outfit' в recipe →
 * GenericCraftCompletionHandler::handleOutfitOutput → characters_outfits (путь S18).
 * Длительности — fallback; override через GameSettings
 * `tier3.faction_armor.<snake>.craft_duration_hours` (соседняя seed-миграция).
 * Idempotent.
 */
class V14AddFactionArmorTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tasks = [
            ['name' => 'craftBunkerPlateArmor',    'name_rus' => 'Крафт: Бункерная бронеплита (фракц.)',     'description' => 'Сборка тяжёлой военной бронеплиты. ~5 часов.', 'min_duration' => 300, 'max_duration' => 300],
            ['name' => 'craftTechnoShieldSuit',    'name_rus' => 'Крафт: Техно-щитовой костюм (фракц.)',     'description' => 'Сборка энергозащитного костюма. ~6 часов.',   'min_duration' => 360, 'max_duration' => 360],
            ['name' => 'craftGhostCityCloak',      'name_rus' => 'Крафт: Плащ Города-призрака (фракц.)',     'description' => 'Пошив маскировочного плаща. ~3 часа.',        'min_duration' => 180, 'max_duration' => 180],
            ['name' => 'craftFarmsteadGuardArmor', 'name_rus' => 'Крафт: Броня хуторской стражи (фракц.)',   'description' => 'Пошив стёганой защитной брони. ~4 часа.',     'min_duration' => 240, 'max_duration' => 240],
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
            ->whereIn('name', ['craftBunkerPlateArmor', 'craftTechnoShieldSuit', 'craftGhostCityCloak', 'craftFarmsteadGuardArmor'])
            ->delete();
    }
}
