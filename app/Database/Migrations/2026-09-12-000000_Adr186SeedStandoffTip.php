<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-186 (pvp-detection-clarity-11) — discoverability-намёк /tips про тревогу базы.
 *
 * Тот, кто в «📖 Путь новичка» (раздел `standoff`) не заходит, узнаёт о механике здесь:
 * атака по игроку на его же базе с целой обороной не бьёт сразу — сначала тревога и
 * три хода защитника, а окно конечно. Конституция TIPS-COVERAGE. Без чисел баланса
 * (длительность окна и кулдаун живут в `GameSettings`, ADR-186 §6) — markdown-safe
 * (парные *), utf8mb4, idempotent по `title_en`.
 */
class Adr186SeedStandoffTip extends Migration
{
    public function up(): void
    {
        $titleEn = 'BaseStandoffAlert';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $content = '🛡️ *Тревога базы:* если кто-то атакует тебя на клетке твоей же базы, а оборона '
            . 'цела — бой не начнётся мгновенно. Бот пришлёт тревогу и даст время среагировать: '
            . '*«🛡 Укрыться»* (разморозить нападавшего и ненадолго получить бонус к защите именно '
            . 'против него), *«🏃 Убежать»* (снять тревогу и прыгнуть подальше) или *«⚔️ Ударить '
            . 'первым»* (самому пойти в атаку). Не ответишь — '
            . 'время выйдет само, и нападавший снова сможет нажать «⚔️ Атаковать» (бой сам собой не '
            . 'начинается). Это разовое право среагировать, а не постоянный щит — не рассчитывай '
            . 'продержаться под чужой тревогой вечно.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛡️ Тревога базы',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'BaseStandoffAlert')->delete();
    }
}
