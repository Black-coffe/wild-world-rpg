<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Каждый бой — в журнале» (TIPS-COVERAGE, CLAUDE.md; w2-n7-combat).
 *
 * W2.N7 дал журнал боёв: PvE, PvP и дуэли с разбором по раундам — в боте «📜 Мои бои» («⚙️ Ещё», арена, рейтинг) и
 * кнопка «📜 Разбор боя» под итогом, на сайте «⚔️ Бои». До этого 767 авто-боёв в месяц приходили только итогом.
 * Соседний совет «⚔️ Как подраться с живым игроком» — про то, КАК начать бой; этот — ГДЕ разобрать прошедший. Не дубль.
 *
 * Idempotent по title_en='BattleJournal'. Без чисел баланса, media-off самодостаточен, markdown-safe (парные *),
 * категория «бой» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedBattleJournalTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'BattleJournal';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '📜 *Каждый бой остаётся в журнале.* С тварью, PvP или дуэль на арене — я записываю по раундам, кто '
            . 'бил, сколько снял и сколько осталось. Под итогом боя жми *📜 Разбор боя*, а все свои бои найдёшь в '
            . '*⚙️ Ещё → 📜 Мои бои*. Играешь на сайте — открой *⚔️ Бои* в нижнем меню. Проиграл — загляни в разбор: '
            . 'видно, где не хватило брони или удачи.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '📜 Разбор боя по раундам',
            'title_en'   => $titleEn,
            'tip_type'   => 'бой',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'BattleJournal')->delete();
    }
}
