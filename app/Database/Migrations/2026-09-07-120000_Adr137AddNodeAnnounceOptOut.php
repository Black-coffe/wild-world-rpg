<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB11 (ADR-137 «Узлы») — per-char opt-out «Сводки с пустоши».
 *
 * `characters.node_announce_enabled` (0/1, default 1 = opt-in). Игрок отключает ежедневный дайджест
 * анонсов в «⚙️ Настройки» (тумблер показывается только при активном `world.nodes.announce_enabled`).
 * Паттерн `daily_tips_enabled` (ADR-038). ⚠ ОБЯЗАТЕЛЬНО в `$allowedFields` CharacterModel (иначе
 * Model::update фильтрует в пустой → DataException; бил дважды — disable_media/last_planted_crop).
 *
 * WipeManifest: `characters`=CHARACTER_RESET; колонка НЕ в `characterResetValues` → это ПРЕФЕРЕНС,
 * переживает вайп (как disable_media/daily_tips_enabled). Таблица уже классифицирована → coverage цел.
 */
class Adr137AddNodeAnnounceOptOut extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('node_announce_enabled', 'characters')) {
            $this->forge->addColumn('characters', [
                'node_announce_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 1,
                    'after'      => 'daily_tips_enabled',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('node_announce_enabled', 'characters')) {
            $this->forge->dropColumn('characters', 'node_announce_enabled');
        }
    }
}
