<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-120 (E20) — нейтральная квест-подводка к автоматизации.
 *
 * Audit: единственный квест про Мастерскую робототехники («Робомастерская») —
 * фракционный (Инженеры, L14) → невидим ~97% игроков; воронка L10+ (26 чаров) →
 * RoboticsWorkshop (6 владельцев) держится только на самооткрытии. Даём СТАРТАБЕЛЬНЫЙ
 * корневой квест (building_level из whitelist isExtendedStartableRoot, faction_id=NULL,
 * без prerequisite) на постройку Мастерской L1 — мост к роботам/дронам и хабу «Ангар».
 *
 * Награда 5200 — полоса E10/ADR-111 (L12 между L10 и L15=6000). Гейтится живым
 * `quests.extended_enabled` — контент, не механизм. quests = KEEP → WipeManifest
 * не затрагивается. Idempotent (по title_en).
 */
class Adr120AutomationLeadQuest extends Migration
{
    public function up(): void
    {
        $titleEn = 'AutomationWorkshop';

        $exists = $this->db->table('quests')->where('title_en', $titleEn)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('quests')->insert([
            'title_ru'           => 'Своя автоматика',
            'title_en'           => $titleEn,
            'description'        => 'Руки выжившего не должны делать всё сами. Построй Мастерскую робототехники — '
                . 'она открывает роботов-добытчиков и исследователей, работающих часами без тебя, '
                . 'и дроны: разведка, доставка груза, ремонт, защита базы. Ангар ждёт на экране базы.',
            'status'             => 'active',
            'min_level'          => 12,
            'reward_type'        => 'gold',
            'reward'             => 5200,
            'prerequisite_quest' => null,
            'objective_type'     => 'building_level',
            'objective_target'   => 'RoboticsWorkshop',
            'objective_qty'      => 1,
            'branch_group'       => null,
            'branch_label'       => null,
            'faction_id'         => null,
        ]);
    }

    public function down(): void
    {
        $this->db->table('quests')->where('title_en', 'AutomationWorkshop')->delete();
    }
}
