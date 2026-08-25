<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-176 (community-chat-bot), story 15 — discoverability-намёк /tips про существование
 * общего чата сообщества в Telegram.
 *
 * Ровно один совет (Non-goals story: без механик игры). Сигналит, что чат есть, что Роби
 * там читает и отвечает, и куда за подробностями — раздел `/guide` → «💬 Общий чат»
 * (ключ `chat`, добавлен той же story). Категория `общие` — совет не про конкретную
 * механику. markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class Adr176SeedCommunityChatTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'Adr176CommunityChatExists';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '💬 Знал, что у сообщества есть общий чат? Там живые выжившие помогают друг '
            . 'другу — а если вопрос повиснет без ответа, иногда отвечает и сам Роби. Подробнее '
            . '(где искать, чего ждать и куда с личным вопросом) — команда */guide* → раздел '
            . '*«💬 Общий чат»*.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '💬 Общий чат сообщества',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'Adr176CommunityChatExists')->delete();
    }
}
