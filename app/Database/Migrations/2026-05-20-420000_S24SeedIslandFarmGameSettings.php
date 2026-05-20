<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S24 (v0.51.204) — spawn-cap GameSettings для Island-Farm
 * (constitutional admin-tunable balance, ADR-024; ROADMAP-CRAFT §8 Фаза 5).
 *
 * Зеркалит S21-паттерн: WorldObjectGeneratorHandler::effectiveMaxCount() для
 * strategic читает cap отсюда (`world.strategic.islandfarm.max_spawns`),
 * fallback — world_objects.max_count. Категория `world`, value_type=int.
 *
 * Baseline 8 — entry-accessible (как GhostCity для Партизан): Фермеры —
 * ранне-friendly фракция, gate только Hoe, биом Поля (~114k клеток).
 * Idempotent.
 */
class S24SeedIslandFarmGameSettings extends Migration
{
    public function up(): void
    {
        $key = 'world.strategic.islandfarm.max_spawns';

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();
        if (! empty($existing)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => 8,
            'default_value_text' => '8',
            'rationale_text'     => 'Сколько Старых ферм (Фермеры → +500) одновременно на карте. 8 — entry-accessible strategic (как GhostCity у Партизан): Фермеры ранне-friendly, gate только Hoe (Мотыга), биом Поля (~114k клеток). Закрывает асимметрию — до S24 у Фермеров не было discovery-source очков.',
            'effect_text'        => 'WorldObjectGeneratorHandler докидывает IslandFarm до этого числа active-записей в biome_world_object_map. Discovery: +500 Фермерам (Evacuation) + авто-завершение StrategicCaptureIslandFarm (+4000 gold, +100 эндгейм-очков) + фермер-лут (Crops/Fruit/Wood/Clay/Water + аптечки/мотыги).',
            'above_effect_text'  => 'При 40+ Старые фермы повсюду → Фермеры фармят +500, перекос эндгейм-гонки, теряется ценность находки.',
            'below_effect_text'  => 'При 1 даже доступный L10-квест почти невыполним (туман войны на 1000×1000) → Фермеры снова без strategic-источника очков, асимметрия возвращается.',
            'recommended_min'    => '1',
            'recommended_max'    => '20',
            'hard_min'           => '0',
            'hard_max'           => '200',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'world.strategic.islandfarm.max_spawns')
            ->delete();
    }
}
