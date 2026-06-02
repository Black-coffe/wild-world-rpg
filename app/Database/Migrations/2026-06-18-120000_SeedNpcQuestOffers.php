<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 4 — назначение квестов нейтральным NPC (квестгиверы).
 *
 * Связывает 3 нейтралов с существующими extended-квестами (ADR-088), тематично:
 *   • Странник пустоши → CollectWoodStockpile (собрать древесину)
 *   • Мусорщик        → CollectIronstoneCache (собрать железную руду — он ценит металл)
 *   • Отшельник       → GrowthMilestone10 (достичь 10 ур. — наставление выжить)
 * Активны только при npc.questgiver_enabled=ON. Idempotent (по npc_name_en).
 */
class SeedNpcQuestOffers extends Migration
{
    public function up(): void
    {
        $map = [
            'WastelandWanderer' => 'CollectWoodStockpile',
            'ScrapScavenger'    => 'CollectIronstoneCache',
            'SilentHermit'      => 'GrowthMilestone10',
        ];
        foreach ($map as $npcName => $questTitleEn) {
            $this->db->table('npcs')
                ->where('npc_name_en', $npcName)
                ->update(['offers_quest_title_en' => $questTitleEn]);
        }
    }

    public function down(): void
    {
        $this->db->table('npcs')
            ->whereIn('npc_name_en', ['WastelandWanderer', 'ScrapScavenger', 'SilentHermit'])
            ->update(['offers_quest_title_en' => null]);
    }
}
