<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE — дроны начали заряжаться в поле.
 *
 * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен заряжаться везде, на базе
 * быстро, в поле — медленнее». До правки вне базы не набегало ни единицы заряда.
 *
 * Совет про правило, без чисел: доля скорости живёт в админке (`drone.field_charge_percent`)
 * и будет крутиться.
 *
 * Инварианты (ADR-134): markdown-safe, категория «общие» из 14 ENUM, идемпотентность по
 * `title_en`, media-off (ADR-020).
 */
class SeedDroneFieldChargeTip extends Migration
{
    private const TITLE_EN = 'DroneChargesInFieldToo';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        if (! empty($this->db->table('game_tips')->where('title_en', self::TITLE_EN)->get()->getRowArray())) {
            return; // idempotent
        }

        $content = '🔋 *Дроны теперь заряжаются не только дома.* Раньше вне базы заряд стоял '
            . 'намертво: ушёл далеко — и дрон бесполезен до самого возвращения.'
            . "\n\n"
            . 'Теперь батарея набирается и в поле, просто заметно медленнее, чем на своей '
            . 'клейм-клетке. Дома по-прежнему выгоднее — но дальняя вылазка больше не означает '
            . 'мёртвого дрона.'
            . "\n\n"
            . 'Касается всех дронов: разведчика, карго, ремонтника и боевого. Остаток заряда '
            . 'виден на экране каждого из них — в *«🏠 База»* → *«🚁 Ангар»* и на самих экранах дронов.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🔋 Дрон заряжается и в поле',
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
