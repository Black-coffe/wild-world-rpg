<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Раны, которые не лечатся едой — хранилище состояний персонажа.
 *
 * Повод: аудит 18.08.2026 показал, что лекарства и еда конкурировали на одной оси
 * (HP + выносливость), и еда выигрывала — лучшая Аптечка (40/20) слабее обычного
 * Сытного рагу (45/55), а Бинт лечил 2 HP. Сигнал игрока: «непонятен смысл лекарств:
 * быстрее отжираться консервами». Лекарствам нужна своя ось — состояния, которые едой
 * не снимаются. Каталог — `Config\Debuffs`.
 *
 * Одна строка = одно активное состояние. Снятое/истёкшее НЕ удаляем сразу — ставим
 * `cured_at`/`expired_at`, чтобы история лечения читалась (и чтобы «снял ли игрок
 * вообще хоть раз» было замеримо; иначе после активации нельзя отличить «не получают»
 * от «получают и не лечат»).
 *
 * WipeManifest: PLAYER_DATA (прогресс персонажа, есть `character_id`).
 */
class CreateCharacterDebuffs extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('character_debuffs')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'character_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'debuff_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'comment'    => 'Ключ из Config\Debuffs::CATALOG (poison / burn / frostbite / fracture)',
            ],
            'severity' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 1,
                'comment'    => 'Тяжесть 1..3 — множитель силы эффекта',
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Откуда прилетело: pve / event / biome / fall',
            ],
            'applied_at' => ['type' => 'DATETIME', 'null' => false],
            'expires_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'NULL = держится, пока не вылечат',
            ],
            'last_tick_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Когда состояние последний раз ударило (для hp_drain)',
            ],
            'cured_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Снято предметом',
            ],
            'cured_by_item' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'expired_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'comment' => 'Прошло само по сроку',
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        // Главный запрос — «активные состояния этого персонажа».
        $this->forge->addKey(['character_id', 'cured_at', 'expired_at']);
        $this->forge->addKey('debuff_key');
        $this->forge->addForeignKey('character_id', 'characters', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('character_debuffs');
    }

    public function down(): void
    {
        $this->forge->dropTable('character_debuffs', true);
    }
}
