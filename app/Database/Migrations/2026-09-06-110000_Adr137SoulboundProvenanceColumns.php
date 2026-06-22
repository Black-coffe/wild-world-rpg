<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB9 (ADR-137 «Узлы») — провенанс soulbound-трофея для badge «🔒 Метка пустоши».
 *
 * Badge в caption инвентаря/экрана-награды: «🔒 Метка пустоши: с {имя} L{N}, X,Y». Флаг
 * `is_soulbound` (WB2) лишь помечает строку трофеем; чтобы показать ОТКУДА он, добавляем
 * провенанс: имя поверженного узла, его уровень на момент килла, координаты. Заполняет
 * BossLootService::grantSoulboundTrophy при выдаче.
 *
 * WipeManifest (ADR-087): characters_weapons/outfits уже PLAYER_DATA → новые колонки покрыты
 * классификацией таблицы; createTable не вызывается → coverage-тест зелёный. Идемпотентно
 * (fieldExists). Добавляются в $allowedFields соответствующих моделей (урок is_soulbound/disable_media).
 */
class Adr137SoulboundProvenanceColumns extends Migration
{
    /** @var list<string> */
    private array $tables = ['characters_weapons', 'characters_outfits'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            $cols = [];
            if (! $this->db->fieldExists('soulbound_source', $table)) {
                $cols['soulbound_source'] = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'is_soulbound'];
            }
            if (! $this->db->fieldExists('soulbound_level', $table)) {
                $cols['soulbound_level'] = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'soulbound_source'];
            }
            if (! $this->db->fieldExists('soulbound_coords', $table)) {
                $cols['soulbound_coords'] = ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'soulbound_level'];
            }
            if ($cols !== []) {
                $this->forge->addColumn($table, $cols);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            foreach (['soulbound_source', 'soulbound_level', 'soulbound_coords'] as $col) {
                if ($this->db->fieldExists($col, $table)) {
                    $this->forge->dropColumn($table, $col);
                }
            }
        }
    }
}
