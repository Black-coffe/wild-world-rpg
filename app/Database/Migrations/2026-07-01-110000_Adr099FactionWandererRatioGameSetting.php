<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-099 — доля фракционных рядовых в спавне нейтралов (admin-tunable, ADR-024).
 *
 * WandererSpawnHandler делит пул passive-NPC на истинных нейтралов (npc_type='neutral')
 * и фракционных рядовых (npc_type='faction'). На каждый спавн: с вероятностью ratio%
 * берётся фракционный, иначе — истинный нейтрал. **Дефолт 0 → dormant** (фракционные не
 * спавнятся, мир byte-identical с до-ADR-099), несмотря на то что npc.interaction_enabled
 * уже ON на проде. Активация = поднять до рекомендованных ~40 после Tier-3 cold-smoke.
 * Этот же ключ служит killswitch'ем фракционных рядовых (0 = выкл). Idempotent.
 *
 * WipeManifest: game_settings = KEEP (баланс). Новых таблиц нет → coverage не затронут.
 */
class Adr099FactionWandererRatioGameSetting extends Migration
{
    public function up(): void
    {
        $key = 'npc.faction_wanderer_ratio';
        $exists = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'int',
            'value_int'          => 0,
            'value_float'        => null,
            'value_bool'         => null,
            'value_string'       => null,
            'default_value_text' => '0',
            'rationale_text'     => 'Доля фракционных рядовых NPC (%) среди спавнящихся встречных нейтралов. На каждый спавн с этой вероятностью берётся фракционный «массовка»-NPC (Патрульный Милитари / Разведчик Партизан / Техник Инженеров / Работник полей), иначе — истинный нейтрал (Странник/Мусорщик/Отшельник). 0 = dormant (фракционные не появляются). Рекомендуемое live-значение после Tier-3 — ~40: мир ощущается фракционным, но атмосфера одиночек пустоши не вытесняется.',
            'effect_text'        => 'WandererSpawnHandler делит passive-пул по npc_type и выбирает под-пул по этой доле. Фракционные реагируют на фракцию игрока (своя +25 / чужая −15, NpcRelationService) и показывают строку «🛡️ Фракция: …» в экране встречи.',
            'above_effect_text'  => 'Выше (70+) → встречные почти всегда фракционные; истинные нейтралы-одиночки почти исчезают, теряется ощущение «дикой» безфракционной пустоши, фракционная реакция приедается.',
            'below_effect_text'  => 'Ниже (10-) → фракционных почти не встретить; фича ощущается мёртвой, фракционная принадлежность игрока в мире почти не отражается (только 4 именных).',
            'recommended_min'    => '20',
            'recommended_max'    => '60',
            'hard_min'           => '0',
            'hard_max'           => '100',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'npc.faction_wanderer_ratio')->delete();
    }
}
