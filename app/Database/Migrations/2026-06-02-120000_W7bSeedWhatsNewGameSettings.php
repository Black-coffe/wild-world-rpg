<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W7b (ADR-065 Part 2) — killswitch каталога «📚 Что нового».
 *
 * default true → кнопка «📚 Что нового» видна на экране Перс (всегда — правило #4
 * UX-discoverability) и Шаг 7/7 онбординга промоутит каталог. false → кнопка и промо
 * скрываются мгновенно, без миграций (existing-чарам ничего откатывать не нужно).
 *
 * Returning-cooldown ключ (onboarding.whats_new.returning_cooldown_days) НЕ сеется —
 * forced-show вернувшимся игрокам отложен (иначе настройка без reader = BUILT-BUT-DEAD).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Idempotent: пропускает существующий setting_key.
 */
class W7bSeedWhatsNewGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'onboarding.whats_new.enabled',
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => 'true',
            'rationale_text'     => "Killswitch каталога «📚 Что нового» (W7b ADR-065 Part 2). Каталог — branching-справочник по 6 темам (крафт-тиры, роботы, защита базы, дроны, фракции/эндгейм, экономика), закрывает gap «новичок/вернувшийся не знает, что появилось за vNext+vNext2». default=true → кнопка видна на Перс всегда (правило #4) + Шаг 7/7 онбординга её промоутит. false → instant rollback (кнопка и промо скрыты), темы остаются в БД.",
            'effect_text'        => "WhatsNewService::isEnabled() читает ключ. CharacterService.showCharacter() рисует кнопку «📚 Что нового» только при true; GetTrainingStart7Action добавляет промо-кнопку каталога только при true; WhatsNewCatalogAction/WhatsNewTopicAction отвергают вход при false. Темы каталога живут в таблице whats_new_topics (контент tunable через SQL без релиза).",
            'above_effect_text'  => "true: каталог доступен. Новые/вернувшиеся игроки видят структурированный обзор механик и понимают roadmap прогрессии. Непрочитанные темы маркируются 🆕 (characters.whats_new_seen).",
            'below_effect_text'  => "false: каталога нет. Discovery механик снова через accident (UI hover-by-luck) + /tips. Используем как emergency-rollback, если каталог окажется шумным или собьёт фокус первой когорты.",
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $existing = $this->db->table('game_settings')
            ->where('setting_key', $row['setting_key'])
            ->get()
            ->getRowArray();
        if (empty($existing)) {
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'onboarding.whats_new.enabled')
            ->delete();
    }
}
