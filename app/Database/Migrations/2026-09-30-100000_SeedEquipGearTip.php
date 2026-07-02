<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет «как надеть скрафтленное снаряжение».
 *
 * След жалобы игрока Евгения 2026-07-02: «скрафтил куртку, но как её надеть? арсенала
 * нет, в инвентаре не видно». Снаряжение (броня/оружие) не лежит в «🎒 Инвентаре» (там
 * только ресурсы) — оно в «Перс → ⚔️ Экип», а чтобы надеть, нужен Арсенал. Совет
 * закрывает findability-разрыв на уровне проактивной рассылки (дополняет lock-state
 * экрана экипировки + JIT-подсказку OnbHint_armor_no_arsenal).
 *
 * Про навигацию/понятия, БЕЗ хрупких чисел баланса (уровень Арсенала не называем — он
 * тюнится). markdown-safe (парные *), utf8mb4-эмодзи. Idempotent (по title_en).
 * tip_type='крафт' (валидное значение ENUM game_tips.tip_type).
 */
class SeedEquipGearTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'EquipGear';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🧥 *Скрафтил броню или оружие?* Снаряжение не лежит в «🎒 Инвентаре» — там только '
            . 'ресурсы. И оно *не теряется*: ищи его в *«Перс» → «⚔️ Экип»* — там вся броня, одежда и '
            . 'оружие, у каждой вещи кнопка *«Надеть»*. Но чтобы менять экипировку, на базе нужно здание '
            . '*«Арсенал»* — это постройка позднего этапа. Пока Арсенала нет, вещь просто хранится и ждёт '
            . 'своего часа. Возвести Арсенал можно в *«🏠 База» → «🏗 Строить»*.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🧥 Как надеть снаряжение',
            'title_en'   => $titleEn,
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'EquipGear')->delete();
    }
}
