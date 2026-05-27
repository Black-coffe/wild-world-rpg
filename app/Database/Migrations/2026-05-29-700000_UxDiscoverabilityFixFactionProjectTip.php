<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CLAUDE.md §🎮 UX-DISCOVERABILITY (2026-05-27) — фикс tip'а Faction Project.
 *
 * Триггер: tip #59 «Фракционный проект» (`title_en=FactionProject`) обещал путь
 * «👤 Перс → 🤝 Проект фракции», но кнопка появлялась только при
 * `hasChosenFaction && faction.project.enabled`. Игроки без фракции видели tip,
 * но не находили вход — BUILT-BUT-INVISIBLE.
 *
 * Текущий фикс:
 *  1. CharacterService.php — lock-button «🔒 Проект фракции (выбери фракцию)»
 *     для случая `!hasChosenFaction && enabled`.
 *  2. FactionProjectLockedAction — alert с объяснением prerequisite.
 *  3. ↓ Эта миграция — переписать tip-текст с явным prerequisite и подсказкой
 *     про lock-state. Идемпотентно (UPDATE WHERE title_en).
 */
class UxDiscoverabilityFixFactionProjectTip extends Migration
{
    public function up(): void
    {
        $newContent = '🤝 *Фракц-проект:* асинхронный кооператив — члены фракции сбрасывают золото в общий пул когда удобно. По достижении порога — −10% на крафт всем членам фракции на 24 часа. Работает при любом числе членов: соло-Фермер копит сам себе, большая фракция — вскладчину.' . "\n\n"
            . '🔓 *Доступ:* с 10 уровня + выбранная фракция. *Вход:* 👤 Перс → 🤝 Проект фракции. Нет фракции — сначала ⚑ Выбрать фракцию там же.';

        $this->db->table('game_tips')
            ->where('title_en', 'FactionProject')
            ->update([
                'content'    => $newContent,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function down(): void
    {
        // Откат тривиален — оригинальный текст в V27SeedContentPassTipsV14ToV25 (PHP-array seed).
        // Down — no-op: down не должен подкладывать «старый куцый» текст обратно.
    }
}
