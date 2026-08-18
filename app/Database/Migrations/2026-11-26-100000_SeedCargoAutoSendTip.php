<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE — у карго-дрона появился автовывоз.
 *
 * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен иметь возможность
 * автоматического перемещения ресурса из рюкзака на склад, начиная с высшей редкости и
 * по убыванию, кроме аптечки, еды и воды».
 *
 * Совет про путь и правило отбора, без чисел (грузоподъёмность и заряд живут в админке).
 *
 * Инварианты (ADR-134): markdown-safe, категория «ресурсы» из 14 ENUM, идемпотентность
 * по `title_en`, media-off (ADR-020).
 */
class SeedCargoAutoSendTip extends Migration
{
    private const TITLE_EN = 'CargoDroneAutoSend';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🤖 *Карго-дрон научился грузиться сам.* На экране *«🚚 Карго-дрон»* рядом со '
            . 'списком ресурсов появилась кнопка *«Автовывоз»*: одно нажатие вместо восьми отправок подряд.'
            . "\n\n"
            . 'Что берёт: самое ценное — по убыванию редкости, пока не заполнится грузоподъёмность. '
            . 'Что *не* берёт: еду, воду и семена — они остаются при тебе, чтобы дрон не увёз запас '
            . 'на выживание и посевной материал.'
            . "\n\n"
            . 'Заряд тратится такой же, как на обычный вылет, — автовывоз экономит нажатия, а не батарею. '
            . 'Стоя на базе, добычу по-прежнему можно сложить на склад руками, вообще без дрона.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🤖 Автовывоз карго-дроном',
            'title_en'   => self::TITLE_EN,
            'tip_type'   => 'ресурсы',
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
