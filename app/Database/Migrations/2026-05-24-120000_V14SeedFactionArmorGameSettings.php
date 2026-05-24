<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V14 (ADR-046) — craft-duration GameSettings для 4 faction armor
 * (constitutional admin-tunable balance, ADR-024). Сиблинг S25 weapon-settings.
 *
 * recipe.duration_override_setting_key читает отсюда, fallback — tasks.min/max_duration.
 * Категория `craft`, value_type=int (часы). Idempotent.
 */
class V14SeedFactionArmorGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'tier3.faction_armor.bunker_plate.craft_duration_hours',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Время крафта Бункерной бронеплиты (Военные, Legendary heavy). 5ч — зеркало BunkerRifle, endgame-броня за захват Бункера. Требует фракцию Военные + StrategicCaptureBunker.',
                'effect_text'        => 'GenericCraftActionStart берёт это значение (override tasks.min/max_duration) при старте craftBunkerPlateArmor. Влияет только на длительность задачи.',
                'above_effect_text'  => 'При 12+ крафт растягивается на полдня → faction-броня ощущается недостижимым гриндом.',
                'below_effect_text'  => 'При 1 Legendary-броня крафтится мгновенно — обесценивает endgame-награду за захват.',
            ],
            [
                'setting_key'        => 'tier3.faction_armor.techno_shield.craft_duration_hours',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Время крафта Техно-щитового костюма (Инженеры, Legendary tech). 6ч — зеркало TechnoBeamShotgun, самая сложная faction-броня (огнестойкость), оправдывает high-tech тематику.',
                'effect_text'        => 'Override длительности craftTechnoShieldSuit. Только длительность.',
                'above_effect_text'  => 'При 12+ — недостижимый гринд для Инженеров.',
                'below_effect_text'  => 'При 1 — топ-броня почти бесплатно по времени, обесценивает.',
            ],
            [
                'setting_key'        => 'tier3.faction_armor.ghost_city_cloak.craft_duration_hours',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Время крафта Плаща Города-призрака (Партизаны, Legendary light). 3ч — зеркало GhostCityKnife, самая быстрая faction-броня (низший required_level L14), под early-friendly Партизан.',
                'effect_text'        => 'Override длительности craftGhostCityCloak. Только длительность.',
                'above_effect_text'  => 'При 12+ — лёгкий плащ крафтится дольше тяжёлых броней, нелогично.',
                'below_effect_text'  => 'При 1 — мгновенный Legendary, обесценивает захват Города-призрака.',
            ],
            [
                'setting_key'        => 'tier3.faction_armor.farmstead_guard.craft_duration_hours',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Время крафта Брони хуторской стражи (Фермеры, Legendary medium). 4ч — зеркало FarmersHarvestScythe, средняя длительность, под Фермеров (захват Старой фермы).',
                'effect_text'        => 'Override длительности craftFarmsteadGuardArmor. Только длительность.',
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
                'tier3.faction_armor.bunker_plate.craft_duration_hours',
                'tier3.faction_armor.techno_shield.craft_duration_hours',
                'tier3.faction_armor.ghost_city_cloak.craft_duration_hours',
                'tier3.faction_armor.farmstead_guard.craft_duration_hours',
            ])
            ->delete();
    }
}
