<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Совет «Из чего ещё варят лекарства» (TIPS-COVERAGE, ADR-134; D-DEAD-RES).
 *
 * Четыре полевых лекарства первого верстака дают потребителя семи накопленным ресурсам без дела: змеиному яду,
 * тропическим насекомым, редким рудам, снегу, капельникам, сере и вулканическому пеплу. Совет — что это сырьё
 * больше не только на продажу и где его варить. Соседи: «💊 Аптечка теперь только про лечение» (PharmacyShelf) —
 * где применять; «🩺 Еда лечит не всё» (Wounds) — чем снимать раны. Этот — из чего варить; не дубль. Прямо говорит,
 * что новые лекарства ран не снимают, чтобы не спорить с советом о ранах.
 *
 * Idempotent по title_en='FieldMedicineFromWastelandStock'. Без чисел баланса (лечение, уровни, время — в админке),
 * media-off самодостаточен, markdown-safe (парные *), категория «крафт» из 14 ENUM. game_tips = KEEP (WipeManifest).
 */
class SeedFieldMedicineTip extends Migration
{
    private const TITLE_EN = 'FieldMedicineFromWastelandStock';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🐍 *Змеиный яд, сера, снег — не только на продажу.* Из того, что копится в рюкзаке после '
            . 'вылазок, теперь варят полевые лекарства: *Сыворотку от яда*, *Хитиновый пластырь*, *Серную мазь* и '
            . '*Минеральные таблетки*. В ход идут змеиный яд, тропические насекомые, редкие руды, снег, капельники, '
            . 'сера и вулканический пепел. Ищи их в *🔨 Крафт → 🔨 Общий крафт → 💊 Лекарства*: на карточке видно, '
            . 'что лечит, сколько сырья нужно и с какого уровня. Пьют их, как обычно, с экрана *💊 Аптечка*. '
            . 'Ран они не снимают — для ран по-прежнему антисептик, бинт и аптечка.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🐍 Лекарства из сырья пустошей',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'крафт',
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
