<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 3 — шанс случайной встречи с нейтральным NPC во время «Похода» (ADR-024).
 *
 * `npc.march_encounter_chance` (float 0..1, default **0** dormant): на каждой пройденной
 * клетке с этим шансом, ЕСЛИ на клетке есть живой passive-NPC и включён npc.interaction_enabled,
 * поход прерывается (pauseMarch reason=npc_encounter) и игроку предлагается встреча. category=world.
 */
class NpcMarchEncounterGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $key = 'npc.march_encounter_chance';
        if (! empty($this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray())) {
            return;
        }
        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'float',
            'value_float'        => 0.0,
            'default_value_text' => '0.0',
            'rationale_text'     => 'Шанс случайной встречи с нейтральным NPC на клетке во время Похода (ADR-089 Фаза 3). 0 = выключено (dormant). Делает мир живым в пути: иногда поход прерывается встречей с выжившим. Двойной гейт: ещё и npc.interaction_enabled.',
            'effect_text'        => 'MarchingTaskHandler::tryRandomNpcEncounter: per-cell RNG < chance И passive-NPC на клетке → pauseMarch(npc_encounter) → игрок выбирает подойти/пройти мимо.',
            'above_effect_text'  => 'Выше 0.15: встречи слишком часты → поход постоянно прерывается, раздражает.',
            'below_effect_text'  => '0 (default): встреч в пути нет (поход не затронут, 0 регрессии).',
            'recommended_min'    => '0.0',
            'recommended_max'    => '0.15',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'npc.march_encounter_chance')->delete();
    }
}
