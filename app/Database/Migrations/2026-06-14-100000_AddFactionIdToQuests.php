<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-088 Фаза 3 — фракционные квесты: колонка `quests.faction_id`.
 *
 * NULL = квест не привязан к фракции (все текущие квесты — 0 регрессии).
 * 1-4 = квест виден/стартуем/прогрессит только для игроков соответствующей фракции
 * (factions.id: 1 Милитари / 2 Партизаны / 3 Инженеры / 4 Фермеры) при killswitch
 * `quests.faction_quests_enabled`=ON. Гейтинг — QuestChainService::factionGateOk.
 *
 * Таблица `quests` = KEEP в Config\WipeManifest (контент-определения; вайп её не трогает),
 * новая колонка player-данных не добавляет → WipeManifestCoverageTest не затронут.
 */
class AddFactionIdToQuests extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('faction_id', 'quests')) {
            return;
        }
        $this->forge->addColumn('quests', [
            'faction_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
                'comment'    => 'ADR-088: фракция квеста (factions.id 1-4); NULL = не фракционный',
                'after'      => 'branch_label',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('faction_id', 'quests')) {
            $this->forge->dropColumn('quests', 'faction_id');
        }
    }
}
