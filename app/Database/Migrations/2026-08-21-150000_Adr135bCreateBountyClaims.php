<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-135 Ф3b — «Доска розыска» (bounty на доминатора): престиж-награда за охоту на угнетателя.
 *
 * Таблица bounty_claims (PLAYER_DATA — Config\WipeManifest, линк hunter_id/target_id):
 * append-only журнал «трофеев охотника». Когда третья сторона (hunter_id) сражает в полевом
 * PvP игрока, держащего активную трофейную подать над другими (target_id = «хозяин»/угнетатель),
 * записывается клейм — престиж (счётчик + титул «Охотник за головами»), БЕЗ золота/ресурсов
 * (owner-pick: 0 эмиссии, 0 вектора отмывания master↔альт-охотник).
 *
 * Сама «доска розыска» (кто сейчас в розыске) — derived live из character_tributes (active
 * master_id), отдельной таблицы не требует. wanted_level — снапшот числа данников жертвы на
 * момент клейма (флейвор «сразил доминатора N-го уровня»).
 *
 * Под killswitch tribute.enabled И tribute.bounty_enabled (оба default false → dormant).
 */
class Adr135bCreateBountyClaims extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'hunter_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],          // кто сразил угнетателя (characters.id)
            'target_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],          // поверженный «хозяин»/доминатор (characters.id)
            'wanted_level' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],              // снапшот числа данников жертвы на момент клейма
            'claimed_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['hunter_id', 'target_id']);   // кулдаун-лукап (один клейм за окно на пару)
        $this->forge->addKey('hunter_id');                  // счётчик трофеев охотника
        $this->forge->addKey('claimed_at');                 // свежесть для доски/хол-оф-фейма
        $this->forge->createTable('bounty_claims', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('bounty_claims', true);
    }
}
