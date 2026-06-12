<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E16 Ф2 (ROADMAP-100, ADR-116/121) — задачи + длительности грандмастер-рецептов.
 *
 * 2 грандмастер-крафта (гейт L40) под существующие craftless легендарки (audit:
 * HydraPlasmaCannon L26 + JuggernautBattleArmor L22 — lootable-only, без рецепта):
 *   - craftHydraPlasmaCannon       (handler_key=generic_craft, реюз пути S17)
 *   - craftJuggernautBattleArmor   (handler_key=generic_craft, реюз пути S18)
 *
 * Замыкают нить охота(E15)→реликвии + север(E16)→Пепел/Кристалл → грандмастер-крафт.
 * Контент (не механика) → без killswitch; натурально-dormant by prerequisite
 * (L40 + ProfessionalWorkbench + дорогие северные ингредиенты). Эконом П8: СИНК.
 *
 * Длительности грандмастер = дольше T3 (8ч) — live-tunable через GameSettings
 * tier3.{weapons,armor}.<name>.craft_duration_hours (override recipe-поля
 * duration_override_setting_key). Idempotent.
 */
class Adr116GrandmasterRecipeTasks extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1) Задачи крафта (generic_craft handler).
        $tasks = [
            [
                'name'         => 'craftHydraPlasmaCannon',
                'name_rus'     => 'Крафт: «Гидра» Плазмопушка (Грандмастер)',
                'description'  => 'Сборка плазменной пушки на трофеях севера. Длительность 8 часов (override через tier3.weapons.hydra_plasma_cannon.craft_duration_hours).',
                'min_duration' => 480,
                'max_duration' => 480,
            ],
            [
                'name'         => 'craftJuggernautBattleArmor',
                'name_rus'     => 'Крафт: Боевая броня «Джаггернаут» (Грандмастер)',
                'description'  => 'Сборка тяжёлой боевой брони на трофеях севера. Длительность 8 часов (override через tier3.armor.juggernaut_battle_armor.craft_duration_hours).',
                'min_duration' => 480,
                'max_duration' => 480,
            ],
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

        // 2) GameSettings длительностей (admin-tunable, ADR-024; category=craft).
        $settings = [
            [
                'key'  => 'tier3.weapons.hydra_plasma_cannon.craft_duration_hours',
                'hrs'  => 8,
                'rus'  => '«Гидра» Плазмопушка',
            ],
            [
                'key'  => 'tier3.armor.juggernaut_battle_armor.craft_duration_hours',
                'hrs'  => 8,
                'rus'  => 'Боевая броня «Джаггернаут»',
            ],
        ];
        $defaults = [
            'category'        => 'craft',
            'value_type'      => 'int',
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => '4',
            'recommended_max' => '16',
            'hard_min'        => '1',
            'hard_max'        => '48',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        foreach ($settings as $s) {
            $exists = $this->db->table('game_settings')->where('setting_key', $s['key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, [
                'setting_key'        => $s['key'],
                'value_int'          => $s['hrs'],
                'default_value_text' => (string) $s['hrs'],
                'rationale_text'     => "Длительность грандмастер-крафта «{$s['rus']}» ({$s['hrs']} ч). Дольше обычного T3 (3-6ч) — это вершина крафта на редких северных трофеях, награда за долгий путь охота→север→крафт.",
                'effect_text'        => 'GenericCraftActionStart override min/max duration задачи на это значение (часы→минуты). Live-tunable.',
                'above_effect_text'  => 'Выше (напр. 24ч) — грандмастер-крафт превращается в суточное ожидание, отпугивает даже ветеранов.',
                'below_effect_text'  => 'Ниже (напр. 1ч) — топ-предмет собирается мгновенно, обесценивает редкость трофеев и сам грандмастер-тир.',
            ]));
        }
    }

    public function down(): void
    {
        $this->db->table('tasks')->whereIn('name', [
            'craftHydraPlasmaCannon',
            'craftJuggernautBattleArmor',
        ])->delete();
        $this->db->table('game_settings')->whereIn('setting_key', [
            'tier3.weapons.hydra_plasma_cannon.craft_duration_hours',
            'tier3.armor.juggernaut_battle_armor.craft_duration_hours',
        ])->delete();
    }
}
