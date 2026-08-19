<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-058 (продолжение) — совет о том, ГДЕ живёт техника и как её запустить.
 *
 * Повод (19.08.2026, живая игра): «Как запустить дрон разведчик? Лежит в
 * рюкзаке заряженный, кнопки нет» — рюкзак (`CraftedResourcesAction`) показывает
 * полку «🛸 Дроны», но ни одной кнопки к технике: игрок видит предмет и не видит
 * дверь. W2SeedDroneScoutTip уже объясняет вход в крафт; этот совет — про запуск
 * уже готового дрона, отдельный ракурс, не дублирует.
 *
 * Путь сверен с кодом: `hangar` → `HangarAction` (кнопка «🤖 Ангар», безусловная,
 * `BaseServiceMessageFormatter:213`) → «🚁 Разведчик» → `droneScoutList`
 * (`DroneScoutCraftedListAction`); вторая дверь — кнопка «🚁 Дрон» на экране карты
 * (`MoveSurfaceService::buildDirectionsKeyboard()`, story 02), видна при первом же
 * рендере, если дрон есть на руках (quantity > 0) — заряд уже не условие показа.
 * Оба callback существуют в `Config\CallbackRoutes`. Без чисел баланса
 * (радиус/заряд/минуты дрейфуют). markdown-safe (парные *), utf8mb4.
 * Idempotent (по title_en).
 */
class Adr058SeedDroneDoorTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'DroneDoorHangar';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🚁 *Дрон в рюкзаке сам не летает.* Рюкзак — витрина, а не пульт: дрон-разведчик '
            . 'нужно запускать из другого места. Найти: *«🏠 База»* → *«🤖 Ангар»* → *«🚁 Разведчик»* — там '
            . 'список твоей техники и кнопка запуска. Вторая дверь короче: пока дрон есть на руках, кнопка '
            . '*«🚁 Дрон»* видна прямо на экране карты.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🚁 Где запускать дрон',
            'title_en'   => $titleEn,
            'tip_type'   => 'общие',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DroneDoorHangar')->delete();
    }
}
