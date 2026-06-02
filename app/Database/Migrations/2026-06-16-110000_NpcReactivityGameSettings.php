<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 2 — admin-tunable параметры реактивности NPC (ADR-024).
 *
 * Killswitch `npc.reactivity_enabled` (OFF dormant) + дельты relation_score от действий +
 * пороги attitude. При ON: NPC помнит игрока, приветствие и доступные действия зависят от
 * отношения. Idempotent.
 */
class NpcReactivityGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['setting_key' => 'npc.reactivity_enabled', 'category' => 'world', 'value_type' => 'bool', 'value_bool' => 0, 'default_value_text' => 'false',
             'rationale_text' => 'Killswitch реактивности NPC (ADR-089 Фаза 2): NPC помнит действия игрока (грабёж/убийство/беседа) — отношение влияет на приветствие и доступные действия при следующей встрече. OFF — encounter работает без памяти (Фаза 1). Ship dormant, активация после Tier-3 (поверх npc.interaction_enabled).',
             'effect_text' => 'NpcRelationService::attitude. ON → приветствие/опции зависят от relation_score; враждебный NPC отказывается говорить/торговать. OFF → всегда базовое приветствие + полное меню.',
             'above_effect_text' => 'ON: мир реагирует — последствия за грабёж/убийство, RPG-глубина.',
             'below_effect_text' => 'OFF (default): мгновенный dormant; encounter без памяти.',
             'hard_min' => '0', 'hard_max' => '1'],

            ['setting_key' => 'npc.relation_rob', 'category' => 'combat', 'value_type' => 'int', 'value_int' => -30, 'default_value_text' => '-30',
             'rationale_text' => 'Изменение отношения NPC при ограблении (ADR-089). Отрицательное — грабёж портит отношение. -30 заметно, но не максимум (убийство хуже).',
             'effect_text' => 'NpcActionChoiceAction: успешный/неуспешный грабёж → adjust(relation, rob).',
             'above_effect_text' => 'Ближе к 0: грабёж почти не злит NPC — последствий нет.',
             'below_effect_text' => 'Сильно ниже: один грабёж → вечная вражда вида NPC.',
             'recommended_min' => '-60', 'recommended_max' => '-10', 'hard_min' => '-1000', 'hard_max' => '0'],

            ['setting_key' => 'npc.relation_kill', 'category' => 'combat', 'value_type' => 'int', 'value_int' => -60, 'default_value_text' => '-60',
             'rationale_text' => 'Изменение отношения при убийстве NPC (ADR-089). Худшее действие — сильный минус.',
             'effect_text' => 'NpcActionChoiceAction: убийство → adjust(relation, kill).',
             'above_effect_text' => 'Ближе к 0: убийство без последствий для отношения.',
             'below_effect_text' => 'Сильно ниже: вид NPC сразу враждебен после одной смерти.',
             'recommended_min' => '-100', 'recommended_max' => '-30', 'hard_min' => '-1000', 'hard_max' => '0'],

            ['setting_key' => 'npc.relation_attack', 'category' => 'combat', 'value_type' => 'int', 'value_int' => -25, 'default_value_text' => '-25',
             'rationale_text' => 'Изменение отношения при нападении (не летальном) на NPC (ADR-089).',
             'effect_text' => 'NpcActionChoiceAction: напасть → adjust(relation, attack).',
             'above_effect_text' => 'Ближе к 0: агрессия без последствий.',
             'below_effect_text' => 'Сильно ниже: одна драка → вражда.',
             'recommended_min' => '-50', 'recommended_max' => '-10', 'hard_min' => '-1000', 'hard_max' => '0'],

            ['setting_key' => 'npc.relation_talk', 'category' => 'combat', 'value_type' => 'int', 'value_int' => 5, 'default_value_text' => '5',
             'rationale_text' => 'Изменение отношения при мирной беседе/расспросе NPC (ADR-089). Небольшой плюс — мирное общение сближает.',
             'effect_text' => 'NpcActionChoiceAction: поговорить/спросить → adjust(relation, talk).',
             'above_effect_text' => 'Высоко: пара бесед → лучший друг, дёшево.',
             'below_effect_text' => '0/минус: беседа не улучшает отношение.',
             'recommended_min' => '1', 'recommended_max' => '15', 'hard_min' => '0', 'hard_max' => '1000'],

            ['setting_key' => 'npc.relation_trade', 'category' => 'combat', 'value_type' => 'int', 'value_int' => 10, 'default_value_text' => '10',
             'rationale_text' => 'Изменение отношения при торговле с NPC (ADR-089). Торговля — взаимовыгодна, плюс больше беседы.',
             'effect_text' => 'NpcActionChoiceAction: торговать → adjust(relation, trade).',
             'above_effect_text' => 'Высоко: торг-фарм отношения.',
             'below_effect_text' => '0/минус: торговля не сближает.',
             'recommended_min' => '2', 'recommended_max' => '25', 'hard_min' => '0', 'hard_max' => '1000'],

            ['setting_key' => 'npc.relation_hostile_at', 'category' => 'combat', 'value_type' => 'int', 'value_int' => -50, 'default_value_text' => '-50',
             'rationale_text' => 'Порог враждебности (ADR-089): relation_score ≤ этого → NPC враждебен (отказ говорить/торговать, только бой/уход).',
             'effect_text' => 'NpcRelationService::attitude → hostile при score ≤ порога.',
             'above_effect_text' => 'Ближе к 0: NPC легко становятся враждебными.',
             'below_effect_text' => 'Сильно ниже: почти никогда не враждебны.',
             'recommended_min' => '-100', 'recommended_max' => '-20', 'hard_min' => '-10000', 'hard_max' => '0'],

            ['setting_key' => 'npc.relation_friendly_at', 'category' => 'combat', 'value_type' => 'int', 'value_int' => 30, 'default_value_text' => '30',
             'rationale_text' => 'Порог дружелюбия (ADR-089): relation_score ≥ этого → NPC дружелюбен (тёплое приветствие, бонусы беседы).',
             'effect_text' => 'NpcRelationService::attitude → friendly при score ≥ порога.',
             'above_effect_text' => 'Высоко: дружбу заслужить трудно.',
             'below_effect_text' => 'Низко: дружелюбие достигается легко.',
             'recommended_min' => '15', 'recommended_max' => '80', 'hard_min' => '0', 'hard_max' => '10000'],

            ['setting_key' => 'npc.relation_faction_same', 'category' => 'combat', 'value_type' => 'int', 'value_int' => 25, 'default_value_text' => '25',
             'rationale_text' => 'Модификатор отношения, если фракция NPC совпадает с фракцией игрока (ADR-089 faction-aware). Свои теплее с порога.',
             'effect_text' => 'NpcRelationService::attitude добавляет этот модификатор к relation_score при совпадении faction_id (NPC с faction_id 1-4).',
             'above_effect_text' => 'Высоко: соплеменники сразу дружелюбны.',
             'below_effect_text' => '0: фракция не влияет на стартовое отношение.',
             'recommended_min' => '0', 'recommended_max' => '50', 'hard_min' => '0', 'hard_max' => '10000'],

            ['setting_key' => 'npc.relation_faction_other', 'category' => 'combat', 'value_type' => 'int', 'value_int' => -15, 'default_value_text' => '-15',
             'rationale_text' => 'Модификатор отношения, если фракция NPC отличается от фракции игрока (ADR-089 faction-aware). Чужаки холоднее.',
             'effect_text' => 'NpcRelationService::attitude добавляет (отрицательный) модификатор к relation_score при разных faction_id (оба 1-4).',
             'above_effect_text' => '0: чужая фракция не влияет.',
             'below_effect_text' => 'Сильно ниже: иные фракции сразу враждебны.',
             'recommended_min' => '-40', 'recommended_max' => '0', 'hard_min' => '-10000', 'hard_max' => '0'],
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
        $this->db->table('game_settings')->whereIn('setting_key', [
            'npc.reactivity_enabled', 'npc.relation_rob', 'npc.relation_kill', 'npc.relation_attack',
            'npc.relation_talk', 'npc.relation_trade', 'npc.relation_hostile_at', 'npc.relation_friendly_at',
        ])->delete();
    }
}
