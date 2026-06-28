<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S8 (ROADMAP-RETENTION-10, ADR-146) — титул-награда реферальной петли «Зовущий».
 *
 * source_type='referral' — TitleCheckCron такой тип ПРОПУСКАЕТ (qualifyingCharacterIds знает только
 * level/achievement → []), титул выдаётся ИМПЕРАТИВНО из ReferralService::qualifyAndReward через
 * TitleService::award (тот же путь, что awardByBossKill для боссов). source_ref='1' = «≥1 дозревший
 * приглашённый» (информативно; порог-логика — в ReferralService).
 *
 * 🔴 НАГРАДА НЕ СИЛА: титул = чистый honor-тег (ADR-112), 0 PvE/PvP/эконом-преимущества (П9).
 * Виден сразу (titles.enabled=1 на проде). titles = KEEP. Idempotent (title_key).
 */
class S8SeedZovushchiyTitle extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'title_key'   => 'referral_caller',
            'name'        => 'Зовущий',
            'description' => 'Позови выжившего: пригласи друга по своей ссылке. Когда он освоится на острове '
                . '(дорастёт до порога), ты получишь этот титул. Ты вернул в мир ещё одного человека.',
            'source_type' => 'referral',
            'source_ref'  => '1',
            'icon'        => '📣',
            'sort_order'  => 90,
            'enabled'     => 1,
        ];

        $exists = $this->db->table('titles')->where('title_key', $row['title_key'])->countAllResults();
        if ($exists === 0) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('titles')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('titles')->where('title_key', 'referral_caller')->delete();
    }
}
