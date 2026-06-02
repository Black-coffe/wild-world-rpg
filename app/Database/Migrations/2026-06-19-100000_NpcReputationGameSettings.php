<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 5 — репутация-тиры: порог «Союзник» + множитель предательства (ADR-024).
 *
 * Достраивает Фазу 2 (relation_score): именованные ступени (Враг/Чужак/Незнакомец/Знакомый/
 * Союзник) + механика предательства (враждебное действие против друга/союзника бьёт сильнее).
 * Под общим killswitch npc.reactivity_enabled. category=combat. Idempotent.
 */
class NpcReputationGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['setting_key' => 'npc.relation_ally_at', 'category' => 'combat', 'value_type' => 'int', 'value_int' => 80, 'default_value_text' => '80',
             'rationale_text' => 'Порог ступени «Союзник» (ADR-089 Ф5): relation_score ≥ этого → высшая дружелюбная ступень (поверх friendly_at). Союзничество заслуживается долго.',
             'effect_text' => 'NpcRelationService::standing → tier=ally при score ≥ порога; иначе friendly (≥friendly_at) / neutral / wary / enemy.',
             'above_effect_text' => 'Высоко: союзника почти не достичь.',
             'below_effect_text' => 'Ниже friendly_at: ступень теряет смысл (сливается с friendly).',
             'recommended_min' => '40', 'recommended_max' => '200', 'hard_min' => '0', 'hard_max' => '100000'],

            ['setting_key' => 'npc.relation_betrayal_mult', 'category' => 'combat', 'value_type' => 'float', 'value_float' => 2.0, 'default_value_text' => '2.0',
             'rationale_text' => 'Множитель урона отношению при враждебном действии (грабёж/убийство/нападение) против ДРУЖЕЛЮБНОГО (friendly/ally) NPC — предательство (ADR-089 Ф5). 2.0 = вдвое больнее: доверие, обманутое, не прощается легко.',
             'effect_text' => 'NpcRelationService::registerAction: если standing≥friendly и действие враждебное → delta × множитель.',
             'above_effect_text' => 'Выше: одно предательство друга → мгновенный враг.',
             'below_effect_text' => '1.0: предательство не наказывается сильнее обычного (механика выключена).',
             'recommended_min' => '1.0', 'recommended_max' => '4.0', 'hard_min' => '1', 'hard_max' => '100'],
        ];
        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge(['created_at' => $now, 'updated_at' => $now], $r));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', ['npc.relation_ally_at', 'npc.relation_betrayal_mult'])->delete();
    }
}
