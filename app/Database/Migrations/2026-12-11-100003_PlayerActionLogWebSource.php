<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * web-bridge-p1-01 (ADR-189) — канал `web` в firehose (`player_action_log.source`): действие,
 * пришедшее с сайта (`/play`), пишется как `web`, а не как callback/text.
 */
class PlayerActionLogWebSource extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `player_action_log`
             MODIFY `source` ENUM('callback','command','text','forcereply','other','task','web')
             NOT NULL DEFAULT 'other'"
        );
    }

    public function down(): void
    {
        // Уже записанные web-строки переводим в допустимое значение перед сужением enum.
        $this->db->query("UPDATE `player_action_log` SET `source`='other' WHERE `source`='web'");
        $this->db->query(
            "ALTER TABLE `player_action_log`
             MODIFY `source` ENUM('callback','command','text','forcereply','other','task')
             NOT NULL DEFAULT 'other'"
        );
    }
}
