<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 1 — admin-tunable параметры действия «ограбить» NPC (ADR-024).
 *
 * Грабёж нейтрального NPC: RNG-шанс успеха → успех даёт золото в диапазоне (one-shot,
 * спавн уходит), провал → NPC даёт отпор (бой). Параметры наружу в админку, т.к. это
 * экономический фаустет — должен быть под контролем баланса. category=combat. Idempotent.
 */
class NpcRobGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'npc.rob_success_chance',
                'value_type'         => 'float',
                'value_float'        => 0.45,
                'default_value_text' => '0.45',
                'rationale_text'     => 'Базовый шанс успеха грабежа нейтрального NPC (ADR-089). 0.45 — грабёж рискован: чаще NPC даёт отпор (бой), чем отдаёт добро. Намеренно не «лёгкие деньги».',
                'effect_text'        => 'NpcInteractionService::rob: при rand < chance — успех (золото + NPC уходит), иначе провал → бой с NPC.',
                'above_effect_text'  => 'Выше 0.6: грабёж становится дешёвым источником золота → инфляция, обесценивание боя/торговли.',
                'below_effect_text'  => 'Ниже 0.3: грабёж почти всегда провал → игроки не пользуются опцией, механика мертва.',
                'recommended_min'    => '0.30',
                'recommended_max'    => '0.60',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'npc.rob_gold_min',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимум золота при успешном грабеже NPC (ADR-089). Скромная награда — грабёж это ситуативный риск, не фарм-петля.',
                'effect_text'        => 'NpcInteractionService::rob: при успехе золото = rand(rob_gold_min, rob_gold_max).',
                'above_effect_text'  => 'Слишком высокий минимум → грабёж выгоднее PvE/добычи, ломает экономику.',
                'below_effect_text'  => 'Слишком низкий → награда несущественна, опция бессмысленна.',
                'recommended_min'    => '20',
                'recommended_max'    => '200',
                'hard_min'           => '0',
                'hard_max'           => '100000',
            ],
            [
                'setting_key'        => 'npc.rob_gold_max',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'rationale_text'     => 'Максимум золота при успешном грабеже NPC (ADR-089). Потолок ситуативной награды.',
                'effect_text'        => 'NpcInteractionService::rob: при успехе золото = rand(rob_gold_min, rob_gold_max).',
                'above_effect_text'  => 'Высокий потолок → грабёж = джекпот-фарм, инфляция золота.',
                'below_effect_text'  => 'Низкий потолок → разброс награды незаметен.',
                'recommended_min'    => '50',
                'recommended_max'    => '500',
                'hard_min'           => '0',
                'hard_max'           => '100000',
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge([
                'category'   => 'combat',
                'created_at' => $now,
                'updated_at' => $now,
            ], $r));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['npc.rob_success_chance', 'npc.rob_gold_min', 'npc.rob_gold_max'])
            ->delete();
    }
}
