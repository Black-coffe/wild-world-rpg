<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-089 Фаза 4 — NPC-квестгиверы: колонка `npcs.offers_quest_title_en`.
 *
 * NULL = NPC не выдаёт квест. Иначе title_en квеста (из quests), который NPC предлагает
 * при встрече (опция «📜 Задание»). Приём = создание quest_step (движок ADR-088). Связывает
 * систему встреч (ADR-089) с системой квестов (ADR-088).
 *
 * `npcs` = KEEP в WipeManifest (контент-определение); новая колонка не player-данные.
 */
class AddOffersQuestToNpcs extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('offers_quest_title_en', 'npcs')) {
            return;
        }
        $this->forge->addColumn('npcs', [
            'offers_quest_title_en' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
                'comment'    => 'ADR-089 Ф4: title_en квеста, который NPC предлагает (NULL = не квестгивер)',
                'after'      => 'ai_behavior',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('offers_quest_title_en', 'npcs')) {
            $this->forge->dropColumn('npcs', 'offers_quest_title_en');
        }
    }
}
