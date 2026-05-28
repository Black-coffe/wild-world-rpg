<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W11 (vNext2, Фаза 3, ADR-067) — демо-развилка для проверки branching-движка.
 *
 * 3 квеста: branch-point + 2 взаимоисключающие ветки (общий branch_group +
 * prerequisite_quest = выбор одного пути, остальные навсегда закрыты):
 *   DemoScoutCrossroads (branch-point, char_level 2)
 *     ├─ DemoPathSettlement (ветка А, «🤝 Помочь поселению», char_level 3)
 *     └─ DemoPathSalvage    (ветка Б, «🎒 Забрать припасы», char_level 3)
 *
 * Dormant на проде: killswitch `quests.branching_enabled`=OFF + branch-point —
 * objective-квест без prerequisite (никому авто не назначается, в AvailableQuests
 * не листится). Реальный игрок их не видит. Tier-3 — SQL-seed branch-point шага на
 * testbot. Реальный фракционный контент = W12. Idempotent.
 */
class W11SeedDemoBranchQuests extends Migration
{
    public function up(): void
    {
        $quests = [
            // ── Branch-point (без prerequisite, objective char_level) ──
            [
                'title_ru'           => 'Развилка разведчика',
                'title_en'           => 'DemoScoutCrossroads',
                'description'        => 'На перекрёстке у разрушенного указателя ты встречаешь раненого караванщика. Он просит помощи — но рядом брошены и нетронутые припасы. Скоро придётся решать, кому ты на этой пустоши: тому, кто протягивает руку, или тому, кто берёт своё.',
                'status'             => 'active',
                'min_level'          => 1,
                'reward_type'        => 'gold',
                'reward'             => 500,
                'prerequisite_quest' => null,
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 2,
                'branch_group'       => null,
                'branch_label'       => null,
            ],
            // ── Ветка А ──
            [
                'title_ru'           => 'Путь помощи: поселение',
                'title_en'           => 'DemoPathSettlement',
                'description'        => 'Ты выбрал помочь. Караванщик приводит тебя в небольшое поселение выживших — здесь тебя запомнят как друга. Докажи, что не зря: достигни 3 уровня, защищая этих людей.',
                'status'             => 'active',
                'min_level'          => 1,
                'reward_type'        => 'gold',
                'reward'             => 1500,
                'prerequisite_quest' => 'DemoScoutCrossroads',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 3,
                'branch_group'       => 'demo_crossroads',
                'branch_label'       => '🤝 Помочь поселению',
            ],
            // ── Ветка Б ──
            [
                'title_ru'           => 'Путь добычи: припасы',
                'title_en'           => 'DemoPathSalvage',
                'description'        => 'Ты выбрал забрать припасы себе. Караванщик уходит ни с чем, но твои сумки полны — на пустоши выживает сильнейший. Распорядись добычей с умом: достигни 3 уровня.',
                'status'             => 'active',
                'min_level'          => 1,
                'reward_type'        => 'gold',
                'reward'             => 1500,
                'prerequisite_quest' => 'DemoScoutCrossroads',
                'objective_type'     => 'char_level',
                'objective_target'   => null,
                'objective_qty'      => 3,
                'branch_group'       => 'demo_crossroads',
                'branch_label'       => '🎒 Забрать припасы',
            ],
        ];

        foreach ($quests as $q) {
            $exists = $this->db->table('quests')->where('title_en', $q['title_en'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('quests')->insert($q);
        }
    }

    public function down(): void
    {
        $this->db->table('quests')->whereIn('title_en', [
            'DemoScoutCrossroads', 'DemoPathSettlement', 'DemoPathSalvage',
        ])->delete();
    }
}
