<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CLAUDE.md §🎮 UX-DISCOVERABILITY — content-coverage для W1+W2 (ADR-058).
 *
 * Drone-recon фича задеплоена в `v0.51.270` (W3a end), но в `/tips` о ней
 * упоминания не было: новый игрок мог никогда не узнать что дроны существуют.
 * Заодно (audit 2026-05-27) обнаружено что UI-входа в крафт DroneScout тоже
 * не было — фикс в commit'е этой же сессии: кнопка «🚁 Дрон-разведчик» в
 * StandardCraftingAction + `droneScout` callback + DroneScoutCraftInfoAction.
 *
 * Tip-текст указывает АКТУАЛЬНЫЙ путь и prerequisite (Workshop L1 + ресурсы),
 * чтобы соответствовать конституционной норме «tip-consistency».
 * Idempotent (INSERT IF NOT EXISTS по title_en='DroneScout').
 */
class W2SeedDroneScoutTip extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $titleEn = 'DroneScout';
        $exists  = $this->db->table('game_tips')->where('title_en', $titleEn)->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $content = '🚁 *Дрон-разведчик:* ручной квадрокоптер из salvaged motors. Запускаешь с любой клетки — мгновенно открывает зону радиусом 10 (≈441 клеток + биомы вокруг) без передвижения. Один запуск тратит заряд полностью. Восстанавливается на базе ~2 часа.' . "\n\n"
            . '🔓 *Гейт крафта:* 🏭 Мастерская робототехники L1 + 8 000 🪙 + Янтарь×4 / Смола деревьев×25 + Стекло (мешки)×2 / Ткань×8 / Металлолом×30.' . "\n\n"
            . '*Вход в крафт:* 🛠 Крафт → 🔧 Стандартный верстак → 🚁 Дрон-разведчик. После крафта кнопка «🚁 Дрон» появится в меню движения и в Действиях.';

        $this->db->table('game_tips')->insert([
            'title_en'   => $titleEn,
            'title_ru'   => '🚁 Дрон-разведчик',
            'tip_type'   => 'крафт',
            'content'    => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_tips')->where('title_en', 'DroneScout')->delete();
    }
}
