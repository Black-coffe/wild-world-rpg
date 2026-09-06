<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * craft-quantity-parity — discoverability-намёк /tips: на карточке рецепта (в т.ч. продвинутых
 * T3-инструментов вроде сапёрной лопаты или алмазной кирки) можно выбрать количество и скрафтить
 * сразу несколько штук — ряд кнопок количества под карточкой, тот же способ, что и у обычных рецептов.
 * markdown-safe (парные *), utf8mb4. Idempotent (по title_en).
 */
class SeedCraftQuantityTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'CraftQuantityBatch';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🛠 *Крафт партией:* на карточке рецепта под описанием предмета есть ряд кнопок с количеством — '
            . 'не обязательно крафтить по одной штуке. Это работает и для продвинутых инструментов вроде '
            . '*сапёрной лопаты* или *алмазной кирки*, точно так же, как для обычных рецептов. Показываются только '
            . 'те ступени, на которые хватает сырья.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🛠 Крафт по несколько штук',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'CraftQuantityBatch')->delete();
    }
}
