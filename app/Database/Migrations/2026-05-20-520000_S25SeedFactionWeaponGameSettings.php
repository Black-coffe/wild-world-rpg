<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S25 (v0.51.205) — craft-duration GameSettings для 4 faction weapons
 * (constitutional admin-tunable balance, ADR-024; ADR-029).
 *
 * Зеркалит S17-паттерн: recipe.duration_override_setting_key читает отсюда,
 * fallback — tasks.min/max_duration. Категория `craft`, value_type=int (часы).
 * Idempotent.
 */
class S25SeedFactionWeaponGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.weapons.bunker_rifle.craft_duration_hours',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Время крафта Бункерной винтовки (Военные, Legendary). 5ч — endgame-оружие, дольше generic T3 (2-6ч), но достижимо за игровую сессию. Требует фракцию Военные + захват Бункера.',
                'effect_text'        => 'GenericCraftActionStart берёт это значение (override tasks.min/max_duration) при старте craftBunkerRifle. Влияет только на длительность задачи.',
                'above_effect_text'  => 'При 12+ крафт растягивается на полдня → faction weapon ощущается недостижимым гриндом.',
                'below_effect_text'  => 'При 1 Legendary-оружие крафтится мгновенно — обесценивает endgame-награду за захват.',
            ],
            [
                'setting_key'        => 'tier3.weapons.techno_beam_shotgun.craft_duration_hours',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Время крафта Технолучевого дробовика (Инженеры, Legendary energy). 6ч — самое сложное faction-оружие (40 dmg energy), оправдывает high-tech тематику.',
                'effect_text'        => 'Override длительности craftTechnoBeamShotgun. Только длительность.',
                'above_effect_text'  => 'При 12+ — недостижимый гринд для Инженеров.',
                'below_effect_text'  => 'При 1 — топ-energy оружие почти бесплатно по времени, обесценивает.',
            ],
            [
                'setting_key'        => 'tier3.weapons.ghost_city_knife.craft_duration_hours',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Время крафта Ножа Города-призрака (Партизаны, Legendary melee). 3ч — самое быстрое faction-оружие (нож, низший required_level L14), под early-friendly Партизан.',
                'effect_text'        => 'Override длительности craftGhostCityKnife. Только длительность.',
                'above_effect_text'  => 'При 12+ — нож крафтится дольше тяжёлых винтовок, нелогично для лёгкого оружия.',
                'below_effect_text'  => 'При 1 — мгновенный Legendary, обесценивает захват Города-призрака.',
            ],
            [
                'setting_key'        => 'tier3.weapons.farmers_harvest_scythe.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Время крафта Косы Жатвы (Фермеры, Legendary melee sweep). 4ч — средняя длительность, под Фермеров (захват Старой фермы).',
                'effect_text'        => 'Override длительности craftFarmersHarvestScythe. Только длительность.',
                'above_effect_text'  => 'При 12+ — недостижимый гринд для Фермеров.',
                'below_effect_text'  => 'При 1 — мгновенный Legendary, обесценивает захват фермы.',
            ],
        ];

        $shared = [
            'category'        => 'craft',
            'value_type'      => 'int',
            'recommended_min' => '1',
            'recommended_max' => '12',
            'hard_min'        => '1',
            'hard_max'        => '48',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'tier3.weapons.bunker_rifle.craft_duration_hours',
                'tier3.weapons.techno_beam_shotgun.craft_duration_hours',
                'tier3.weapons.ghost_city_knife.craft_duration_hours',
                'tier3.weapons.farmers_harvest_scythe.craft_duration_hours',
            ])
            ->delete();
    }
}
