<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v0.51.127 — community idea #2: MeteorImpact event + MeteorShelter craft.
 *
 * Single-impact world event паралельно existing MeteorRain (continuous):
 *   - MeteorImpact: dur 1 min, single-shot, 40% resource damage, defenses через MeteorShelter
 *   - MeteorRain (id=4): dur 35 min, continuous, 15% per event, без defense
 *
 * Інфраструктура F7 reused (effect_kind='damage_resources', existing
 * DamageResourcesEffect strategy — нічого нового у Services/Events/).
 * Single-shot semantics через duration_minutes=1 + tick_chance=1.0:
 * EventTickHandler runs every minute → exactly 1 tick → exactly 1 apply.
 *
 * Inserts 3 rows у 3 tables atomically:
 *   1. tasks: craftMeteorShelter (10-15 min, type=craft)
 *   2. crafted_items: MeteorShelter (defense item, durability_count=1)
 *   3. events: MeteorImpact (global, dur=1, freq=1, protection_item_id linked)
 */
class AddMeteorImpactEvent extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1) tasks row — duration window для GenericCraftActionStart lookup
        $this->db->table('tasks')->insert([
            'name'                       => 'craftMeteorShelter',
            'name_rus'                   => 'Создание Метеоритного укрытия',
            'description'                => 'Сборка переносного укрытия из камня и металла, способного выдержать удар метеорита.',
            'min_duration'               => 10,
            'max_duration'               => 15,
            'type'                       => 'craft',
            'difficulty_level'           => 2,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 1,
            'interruptible'              => 1,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);

        // 2) crafted_items row — defense consumable
        $this->db->table('crafted_items')->insert([
            'name_rus'           => 'Метеоритное укрытие',
            'name_eng'           => 'MeteorShelter',
            'type'               => 'defense',
            'direction_craft'    => 'protection',
            'effect'             => 'damage_reduction',
            'durability_count'   => 1,
            'durability_time'    => null,
            'damage'             => 0,
            'armor'              => 0,
            'hp'                 => 0,
            'character_boost'    => json_encode([['ловкость' => 1, 'интеллект' => 2]]),
            'weight'             => 0.50,
            'price'              => 30.00,
            'stack_size'         => 3,
            'required_level'     => 3,
            'required_resources' => "Камни — 20 шт.\nЖелезная руда — 10 шт.\nДревесина — 15 шт.",
            'crafting_time'      => '10-15 мин',
            'crafting_location'  => 'all',
            'description'        => 'Переносное укрытие из камня и металла. При активном Метеоритном ударе уменьшает потерю ресурсов на 50%. Расходуется не сразу — конструкция переживёт несколько падений, прежде чем рассыпется.',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $shelterId = (int) $this->db->insertID();

        // 3) events row — MeteorImpact (global, single-shot, defenses linked)
        $this->db->table('events')->insert([
            'name'                => 'Метеоритный удар',
            'name_english'        => 'MeteorImpact',
            'description'         => 'Внезапное падение крупного метеорита. Резкий удар выбрасывает шрапнель и осколки породы — часть ресурсов в инвентаре оказывается раздавлена или потеряна. Метеоритное укрытие снижает потери вдвое; нахождение на базе защищает полностью.',
            'biome_ids'           => null,                  // global: any biome
            'event_type'          => 'global',
            'random_coverage'     => false,
            'duration'            => 1,                     // single-shot via 1-minute window
            'frequency_per_week'  => 1,
            'effect_type'         => 'damage',
            'effect_value'        => 40,                    // % resource loss (state_modifier scales)
            'protection_item_id'  => $shelterId,
            'start_time'          => null,
            'end_time'            => null,
            'img_path'            => 'uploads/telegram/default_event_image.png',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('events')->where('name_english', 'MeteorImpact')->delete();
        $this->db->table('crafted_items')->where('name_eng', 'MeteorShelter')->delete();
        $this->db->table('tasks')->where('name', 'craftMeteorShelter')->delete();
    }
}
