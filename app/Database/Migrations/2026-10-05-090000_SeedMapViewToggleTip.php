<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TIPS-COVERAGE (CLAUDE.md, ADR-134) — совет «вид карты переключается кнопкой».
 *
 * След вопроса игрока в чате 2026-07-21: «режим карты хрен поменять уже, да?» — сменить вид
 * можно было ТОЛЬКО слепым вводом `accurate_map`/`beautiful_map`, а узнать о командах — лишь
 * на экране «вид не выбран», который после первого выбора не показывается никогда.
 * Тумблер добавлен в «⚙️ Настройки»; совет доносит его до тех, кто выбор давно сделал.
 *
 * Про навигацию (где кнопка), БЕЗ чисел баланса. markdown-safe (парные *), utf8mb4-эмодзи.
 * Idempotent (по title_en). tip_type='настройки' (валидное ENUM-значение).
 */
class SeedMapViewToggleTip extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $titleEn = 'MapViewToggle';

        if (! empty($this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray())) {
            return;
        }

        $content = '🗺 *Карта бывает двух видов.* Картинка «🗺 Обзор» рисуется либо *точной* '
            . '(пиксель в пиксель — удобно считать координаты), либо *художественной* '
            . '(живописнее, но менее точная). Раньше вид выбирался один раз и намертво; теперь '
            . 'его переключает кнопка: «⚙️ Ещё» → «⚙️ Настройки» → *«Вид карты мира»*. Менять '
            . 'можно сколько угодно раз. Текстовая карта-сетка вокруг тебя и «❓ Легенда» '
            . 'одинаковы при любом выборе — на них вид не влияет.';

        $this->db->table('game_tips')->insert([
            'title_ru'   => '🗺 Точная или художественная карта',
            'title_en'   => $titleEn,
            'tip_type'   => 'настройки',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'MapViewToggle')->delete();
    }
}
