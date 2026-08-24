<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE — совет о выборе базы при возврате телепортом: игроки с несколькими
 * базами сносили лишние, чтобы «попасть на нужную» — цель раньше бралась первой
 * строкой без сортировки и без фильтра по статусу.
 *
 * Повод: story backpack-teleport-base-choice (владелец в чате — «по какому принципу
 * и на какую базу закидывает?»). Story 01/02 добавляют выбор базы на экране телепорта,
 * этот совет объясняет игроку, что теперь так и происходит.
 *
 * Инварианты (ADR-134): про навигацию и понятия, без хрупких чисел баланса; markdown-safe
 * (парные *); utf8mb4-эмодзи; категория «персонаж» из 14 ENUM; идемпотентность по
 * `title_en`; media-off (ADR-020) — весь смысл в тексте.
 */
class SeedTeleportBaseChoiceTip extends Migration
{
    private const TITLE_EN = 'TeleportBaseChoice';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return;
        }

        $content = '🏠 *Несколько баз — телепорт спросит, куда.* Если у тебя больше одной базы, '
            . 'рюкзак-телепорт, золотой возврат, портативный телепорт и бесплатный телепорт за '
            . 'опыт больше не прыгают наугад: сначала покажут список твоих баз с координатами, '
            . 'и ты сам выбираешь нужную кнопкой.'
            . "\n\n"
            . 'Заброшенные базы в этом списке не появляются — целью для возврата бывает только та, '
            . 'что стоит. А если база одна, лишнего экрана не будет: прыжок пойдёт сразу, как раньше.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🏠 Несколько баз — телепорт спросит, куда',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'персонаж',
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
