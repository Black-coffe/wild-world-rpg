<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 1 — killswitch системы встреч/диалогов с NPC (admin-tunable, ADR-024).
 *
 * `npc.interaction_enabled` (bool, default **false** dormant): true — нейтральные NPC
 * (ai_behavior='passive') показывают кнопку «👤 Незнакомец» на клетке и экран встречи с
 * меню действий (напасть/убить/ограбить/поговорить/торговать/спросить); false — dormant
 * (NPC засеяны, но кнопка/экран скрыты, 0 player-эффекта). category=world. Idempotent.
 */
class NpcInteractionEnabledGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (empty($this->db->table('game_settings')->where('setting_key', 'npc.interaction_enabled')->get()->getRowArray())) {
            $this->db->table('game_settings')->insert([
                'setting_key'        => 'npc.interaction_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch системы встреч с нейтральными NPC (ADR-089 Фаза 1): нейтральный NPC (ai_behavior=passive) не атакует первым, при встрече игрок выбирает действие — напасть/убить/ограбить/поговорить/торговать/спросить. true — кнопка «👤 Незнакомец» и экран встречи активны; false — dormant (контент засеян, но скрыт). Ship dormant, активация после Tier-3.',
                'effect_text'        => 'NpcInteractionService::enabled. true → MoveCharacterToDirectionAction добавляет кнопку встречи при наличии passive-NPC на клетке; NpcEncounterAction рендерит меню; NpcActionChoiceAction исполняет выбор; WandererSpawnHandler спавнит нейтралов. false → всё скрыто/не спавнится (враждебные NPC и авто-бой не затронуты).',
                'above_effect_text'  => 'true (вкл): RPG-составляющая — игрок встречает выживших, может говорить/торговать/грабить/убивать; мир перестаёт быть «соло без реакций». Семя для reactivity/квестгиверов (Фазы 2-4).',
                'below_effect_text'  => 'false (выкл, текущий default): мгновенный dormant без редеплоя; существующие враждебные NPC (auto-бой) и караваны не затронуты (0 регрессии).',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        if (empty($this->db->table('game_settings')->where('setting_key', 'npc.wanderer_population')->get()->getRowArray())) {
            $this->db->table('game_settings')->insert([
                'setting_key'        => 'npc.wanderer_population',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Целевая популяция нейтральных NPC-странников на карте (ADR-089). WandererSpawnHandler поддерживает столько живых passive-спавнов. 25 — редкие, но реальные встречи: пустошь пустынна, но не мертва.',
                'effect_text'        => 'WandererSpawnHandler: если живых passive-спавнов < N, досевает на случайные населённые клетки (Y 401-900).',
                'above_effect_text'  => 'Выше 60: странники на каждом шагу — обесценивает встречу, спам-нагрузка на движение.',
                'below_effect_text'  => 'Ниже 10: встретить кого-то почти нереально — механика ощущается мёртвой.',
                'recommended_min'    => '10',
                'recommended_max'    => '60',
                'hard_min'           => '0',
                'hard_max'           => '500',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['npc.interaction_enabled', 'npc.wanderer_population'])
            ->delete();
    }
}
