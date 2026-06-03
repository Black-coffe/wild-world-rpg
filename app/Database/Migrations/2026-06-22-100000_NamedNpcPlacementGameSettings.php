<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Phase 6 (контент-пасс именных NPC) — killswitch размещения именных NPC.
 *
 * `npc.named_placement_enabled` (bool, default **0** dormant): ON → NamedNpcPlacementHandler
 * (cron) держит ровно 1 живой спавн КАЖДОГО именного NPC (npc_type='named') на его стабильной
 * home-клетке (фикс-ландмарк: к NPC можно вернуться, респавн при убийстве). OFF → именные NPC
 * не размещаются (как было до пасса — Корвин был недостижим, 0 спавнов).
 *
 * Фикс-ландмарк выбран владельцем (AskUserQuestion 2026-06-03): тематично (Корвин у рации,
 * вербовщик у аванпоста) + к NPC можно вернуться. Обнаружение — приход на клетку (кнопка
 * «👤 Незнакомец» в move, как у нейтралов). home-клетка персистится в npcs.custom_settings.
 */
class NamedNpcPlacementGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'setting_key'        => 'npc.named_placement_enabled',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => '0',
            'rationale_text'     => 'ADR-089 контент-пасс: именные NPC (Корвин + 4 фракционных представителя) размещаются фикс-ландмарком — крон держит 1 живой спавн каждого на стабильной home-клетке в населённом ярусе (Y 401-900). Default 0 = dormant (раскатываем после Tier-3 живого тапа). До пасса именные NPC вообще не спавнились (WandererSpawnHandler их исключает) → Корвин был недостижим.',
            'effect_text'        => 'NamedNpcPlacementHandler (cron everyMinute): при ON для каждого npc_type=named поддерживает ровно 1 живой спавн на home-клетке (persist в custom_settings.home_cell; при убийстве — респавн там же). Игрок встречает их приходом на клетку (кнопка «👤 Незнакомец»).',
            'above_effect_text'  => 'ON: все именные NPC присутствуют в мире на своих клетках — достижимы, faction-реакция и квесты работают (нужны interaction_enabled + засеянные деревья).',
            'below_effect_text'  => '0 (default): именные NPC не размещаются — недостижимы (полностью dormant, 0 регрессии).',
            'recommended_min'    => '0',
            'recommended_max'    => '1',
            'hard_min'           => '0',
            'hard_max'           => '1',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        if (empty($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray())) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'npc.named_placement_enabled')->delete();
    }
}
