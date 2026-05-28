<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W13 (vNext2, Фаза 3, ADR-067) — spawn-cap «Сердца острова» (admin-tunable, ADR-024).
 *
 * Зеркалит S21/S24-паттерн: WorldObjectGeneratorHandler::effectiveMaxCount() читает
 * `world.strategic.islandheart.max_spawns` (IslandHeart ∈ STRATEGIC_SPAWN_TYPES).
 *
 * **Default 0 = DORMANT** (зеркало W9/W11/W12 ship-dormant): объект зарегистрирован
 * (status='active'), но генератор не спавнит (0 < 0 → skip) → discovery невозможна →
 * островная арка-капстон не запускается. Активация в конце ROADMAP: флип >0 + branching ON.
 * Idempotent.
 */
class W13SeedIslandHeartSpawnGameSettings extends Migration
{
    public function up(): void
    {
        $key = 'world.strategic.islandheart.max_spawns';
        $existing = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($existing)) {
            return;
        }
        $now = date('Y-m-d H:i:s');

        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => 0,
            'default_value_text' => '0',
            'rationale_text'     => 'Сколько «Сердец острова» (faction-agnostic ландмарк-капстон главы 1, ADR-067 W13) одновременно на карте. Default 0 = DORMANT: объект не спавнится, островная story-arc не запускается. Активация в конце ROADMAP (флип на 1-3) вместе с branching killswitch и консолидированным анонсом.',
            'effect_text'        => 'WorldObjectGeneratorHandler докидывает IslandHeart до этого числа в biome_world_object_map (биом 8, Вулканические). Discovery → +500 + лут + авто-завершение StrategicCaptureIslandHeart → advanceChain → арка (Пробуждение → Откровение → ветвящийся финал «судьба острова»).',
            'above_effect_text'  => 'При 5+ «Сердце острова» теряет уникальность капстона (должно быть редким, почти-единственным финальным открытием главы).',
            'below_effect_text'  => 'При 0 (текущий default) — dormant: капстон выключен до активации. После активации 1-2 достаточно (rare endgame-открытие на 1000×1000).',
            'recommended_min'    => '0',
            'recommended_max'    => '3',
            'hard_min'           => '0',
            'hard_max'           => '50',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'world.strategic.islandheart.max_spawns')->delete();
    }
}
