<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-116 (E16) — миграционный квест «На север» (стартабельный, L25).
 *
 * Цель — collect_resource «Пепел Предтеч» (ForerunnerAsh) ×10: добыть его можно ТОЛЬКО в средней
 * полосе Y300-600 (широтный гейт ADR-116) → квест механически тянет подросшего игрока на север.
 * Стартабельный корень (isExtendedStartableRoot whitelist), без prerequisite, faction NULL → виден
 * всем в «Доступных квестах» при quests.extended_enabled=ON. quests=KEEP. Idempotent (title_en).
 */
class Adr116NorthMigrationQuest extends Migration
{
    public function up(): void
    {
        if ($this->db->table('quests')->where('title_en', 'NorthMigration')->countAllResults() > 0) {
            return;
        }
        $this->db->table('quests')->insert([
            'title_ru'           => 'На север',
            'title_en'           => 'NorthMigration',
            'description'        => 'Перекрёсток шепчет: настоящая добыча — севернее, в средней полосе. Добудь 10 единиц «Пепла Предтеч» — он оседает только там, в землях между новичковым югом и смертельным севером. Заодно осмотрись: там бродят элиты и встречаются аномалии.',
            'status'             => 'active',
            'min_level'          => 25,
            'reward_type'        => 'gold',
            'reward'             => 7000,
            'prerequisite_quest' => null,
            'objective_type'     => 'collect_resource',
            'objective_target'   => 'ForerunnerAsh',
            'objective_qty'      => 10,
            'branch_group'       => null,
            'branch_label'       => null,
            'faction_id'         => null,
        ]);
    }

    public function down(): void
    {
        $this->db->table('quests')->where('title_en', 'NorthMigration')->delete();
    }
}
