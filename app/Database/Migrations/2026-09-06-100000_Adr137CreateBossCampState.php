<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB10 (ADR-137 «Узлы») — анти-кемп LOOT-LOCK: состояние «торчания» игрока у узла.
 *
 * Кемпер («крысятник») засел вплотную к узлу и монополизирует его трофей. Этот ledger считает
 * НЕПРЕРЫВНЫЙ dwell (присутствие в малом радиусе R=`world.nodes.anticamp.radius`) и при превышении
 * `world.nodes.anticamp.dwell_hours` выставляет `loot_locked_until` per-(игрок,узел): убийство такого
 * узла даёт 0 soulbound-ТРОФЕЕВ (золото «Облавы» по вкладу не трогаем) и не засчитывает killer для
 * анонса (WB11). Сброс — при ПЕРВОМ тике вне радиуса (строка удаляется AntiCampDwellHandler).
 *
 * 🔴 Оффлайн-инвариант (конституция «беззащитный оффлайн»): dwell КОПИТСЯ ТОЛЬКО пока перс online
 * (`telegram_users.last_seen` в окне, WB3-реюз ADR-108) → оффлайн НЕ копит, alt-абьюз закрыт. Поэтому
 * dwell — НЕ wall-clock от first_seen (тот учитывал бы оффлайн), а АККУМУЛЯТОР `dwell_minutes`:
 * +1 за каждый online-in-zone тик everyMinute-крона (оффлайн → крон пропускает → счётчик заморожен).
 * first_seen_in_zone_at оставлен для диагностики «кемпит с …».
 *
 * WipeManifest (ADR-087): boss_camp_state = **PLAYER_DATA** (by character) — состояние игрока, новый
 * сезон с нуля. Классификация добавляется в Config\WipeManifest этой же сессией.
 */
class Adr137CreateBossCampState extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'character_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'boss_point_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'first_seen_in_zone_at' => ['type' => 'DATETIME', 'null' => true], // диагностика «кемпит с …»
            'dwell_minutes'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0], // аккумулятор online-минут (оффлайн не копит)
            'loot_locked_until'     => ['type' => 'DATETIME', 'null' => true], // активен лок, пока > NOW и строка жива
            'last_warned_at'        => ['type' => 'DATETIME', 'null' => true], // one-shot warn-пинг ДО лока
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['character_id', 'boss_point_id']); // апсёрт-ключ + дедуп
        $this->forge->addKey('boss_point_id');
        $this->forge->createTable('boss_camp_state', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('boss_camp_state', true);
    }
}
