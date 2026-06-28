<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — реферальная петля «позови выжившего».
 *
 * `referrals` — ребро «кто-кого-привёл» + состояние награды. referrer_user_id / referred_user_id —
 * это telegram_users.id (внутренние, НЕ raw telegram_id; deep-link ref_<users.id>). Каждый
 * приглашённый кредитуется ровно ОДНОМУ реферреру (UNIQUE referred_user_id, first-touch при
 * /start). qualified_at — момент, когда приглашённый достиг qualify-уровня; rewarded_at — момент
 * выдачи реферреру non-power титула «Зовущий».
 *
 * 🔴 Награда — НЕ игровая сила (титул ADR-112), выплата за РЕАЛЬНЫЙ прогресс приглашённого
 * (анти-бот-фарм). cap referral.max_per_referrer, anti-self-invite — в ReferralService.
 *
 * WipeManifest: referrals = PLAYER_DATA (link=[referrer_user_id, referred_user_id], by=telegram) —
 * новый сезон = реферальная гонка с нуля (классифицировано в Config\WipeManifest; coverage-гейт).
 */
class S8CreateReferrals extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('referrals')) {
            return; // idempotent
        }

        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'referrer_user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'referred_user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'referred_character_id'=> ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'qualified_at'         => ['type' => 'DATETIME', 'null' => true],
            'rewarded_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // Один реферрер на приглашённого (first-touch) — UNIQUE гасит гонку повторных /start.
        $this->forge->addUniqueKey('referred_user_id');
        // Cap-COUNT + статистика реферрера.
        $this->forge->addKey('referrer_user_id');
        // Скан cron'а «дозрел, но не награждён».
        $this->forge->addKey(['qualified_at', 'rewarded_at']);
        $this->forge->createTable('referrals', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }

    public function down(): void
    {
        $this->forge->dropTable('referrals', true);
    }
}
