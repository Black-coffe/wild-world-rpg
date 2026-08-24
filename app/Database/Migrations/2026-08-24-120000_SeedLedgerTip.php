<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * chat-requests-batch-08 (TIPS-COVERAGE) — совет про экран «🧾 Куда ушло»
 * (chat-requests-batch-06).
 *
 * Ivan Divan (08.06.2026): «лога движения средств тоже нету и не понятно нихера».
 * Max Syskov: «У меня исчезло 50% ресурсов, сравнивал "сегодня 15:03" и "сейчас"».
 * До этой двери у игрока не было способа увидеть, ЧТО именно списало запас — только
 * итоговую цифру до/после.
 *
 * Без чисел баланса: НЕ называет глубину ленты — она настраиваемая
 * (`economy.ledger.depth`), совет с числом протухнет молча при следующем ребалансе.
 *
 * Инварианты: markdown-safe (парные `*`), категория «общие» из 14 ENUM, идемпотентно
 * по `title_en`, media-off (ADR-020). `game_tips` = KEEP (WipeManifest).
 */
class SeedLedgerTip extends Migration
{
    private const TITLE_EN = 'WhereItWentLedger';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🧾 *Не понимаешь, куда делись золото или ресурсы?* Открой *«🎒 Инвентарь»* → '
            . '*«🧾 Куда ушло»* — лента последних движений твоего запаса: налог за постройки и '
            . 'маяки, покупки и продажи, потери от смерти, эффекты мировых событий. У каждой '
            . 'строки — время и сумма, так что видно не только «сколько», но и «когда» и «из-за чего».'
            . "\n\n"
            . '_Лента показывает не всю историю, а только последние записи — специально коротко, '
            . 'чтобы не тонуть в прошлом._';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧾 Куда ушли ресурсы и золото',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', self::TITLE_EN)->delete();
    }
}
