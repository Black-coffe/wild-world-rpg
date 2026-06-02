<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 — полноценный ветвящийся диалог с NPC.
 *
 * Два killswitch'а (category=world):
 *  - `npc.rich_dialogue_enabled` (bool, default **0** dormant): ON → встреча с нейтралом
 *    диалого-центрична (рендерит корневой узел дерева, действия = исходы реплик act-*).
 *    OFF → старое меню 6 кнопок (0 регрессии).
 *  - `npc.flee_enabled` (bool, default **1**): провокация NPC, который слабее игрока,
 *    приводит к побегу (spawn исчезает). OFF → слабый NPC вместо побега принимает бой.
 */
class NpcRichDialogueGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'npc.rich_dialogue_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'ADR-089 Phase 6: встреча с нейтральным NPC становится диалого-центричной — ветвящееся дерево реплик с явными выборами игрока и последствиями (дружба/провокация→бой/грабёж/побег). Default 0 = dormant (раскатываем после Tier-3 живого тапа).',
                'effect_text'        => 'NpcEncounterAction: при ON и наличии дерева у NPC рендерит корневой узел диалога (действия Напасть/Ограбить/Торговать/Квест = act-* реплики внутри). При OFF — старое меню 6 кнопок.',
                'above_effect_text'  => 'ON: полноценный RPG-диалог со всеми нейтралами (нужны засеянные деревья + пройденный Tier-3).',
                'below_effect_text'  => '0 (default): нейтралы показывают старое меню действий; именной Корвин — своё дерево как было (0 регрессии).',
                'recommended_min'    => '0',
                'recommended_max'    => '1',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'npc.flee_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'ADR-089 Phase 6: если игрок провоцирует NPC, который СЛАБЕЕ его (level+статы), NPC убегает (spawn исчезает, игрок упускает добычу) вместо самоубийственной драки. Делает реакцию мира правдоподобной.',
                'effect_text'        => 'NpcDialogueAction act-provoke / провокация: при hostile-исходе и npcStrongerThan=false → flee (consumeSpawn + парная реплика). При OFF слабый NPC вместо побега принимает бой.',
                'above_effect_text'  => '1 (default): слабые NPC убегают от агрессии — реалистично, но игрок упускает грабёж/убийство.',
                'below_effect_text'  => '0: слабые NPC не убегают, всегда принимают спровоцированный бой (легче фармить, но менее живо).',
                'recommended_min'    => '0',
                'recommended_max'    => '1',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['npc.rich_dialogue_enabled', 'npc.flee_enabled'])
            ->delete();
    }
}
