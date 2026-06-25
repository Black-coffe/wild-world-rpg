<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S3 (ROADMAP-RETENTION-10, ADR-138) — discoverability-совет /tips про ранний рост.
 *
 * После активации `progression.early.enabled` добыча/разведка прокачивают новичка
 * (XP за добычу + ускоренные ранние гейны) — сигналим игроку от лица Роби: «расти,
 * действуя; первые уровни даются быстро; прервать дело на старте не страшно». Про
 * понятия/навигацию, НЕ про хрупкие числа баланса (анти-дрейф). tip_type=`персонаж`
 * (валидный ENUM GameTipsModel). markdown-safe (парные *), media-off, utf8mb4-эмодзи.
 * Idempotent по title_en. game_tips=KEEP → WipeManifest не затрагивается.
 *
 * Сидится ПРИ АКТИВАЦИИ S3 (killswitch ON) — не lying-tip про невидимую фичу.
 */
class Adr138SeedEarlyGatherXpTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'EarlyGatherXp';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '⛏ *Расти, добывая.* На первых уровнях добыча ресурсов и разведка пустоши прокачивают '
            . 'твоего выжившего — прогресс идёт заметно быстрее, чем кажется. Выходи на клетки, собирай воду, '
            . 'дерево, руду, открывай карту — и первые уровни возьмёшь играючи. А там близко и первые анлоки: '
            . 'спортзал, лаборатория, а на 10-м уровне — выбор фракции. Прервать начатое дело на старте не '
            . 'страшно: твой рост это не отъедает. Не сиди в безопасности — пустошь награждает тех, кто действует.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '⛏ Добыча прокачивает',
            'title_en'   => $titleEn,
            'tip_type'   => 'персонаж',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'EarlyGatherXp')->delete();
    }
}
