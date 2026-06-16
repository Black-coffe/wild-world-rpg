<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-132 Ф2 — строгий триггер «день за действие» для login-стрика (надстройка над ADR-108 Ф3).
 *
 *   • returnability.streak.strict_action_trigger (bool, default false → ВЫКЛ = текущее поведение
 *     ADR-108: день засчитывается на первом взаимодействии). При ON день серии засчитывается только
 *     если игрок СОВЕРШИЛ осмысленное действие сегодня (есть character_tasks со start_time за сегодня —
 *     разведка/добыча/крафт/стройка/марш). Это меняет поведение ЖИВОЙ фичи → default OFF + анонс
 *     при активации.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class Adr132StrictActionTriggerSetting extends Migration
{
    public function up(): void
    {
        $key = 'returnability.streak.strict_action_trigger';
        $existing = $this->db->table('game_settings')->where('setting_key', $key)->get()->getRowArray();
        if (! empty($existing)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('game_settings')->insert([
            'setting_key'        => $key,
            'category'           => 'world',
            'value_type'         => 'bool',
            'value_bool'         => 0,
            'default_value_text' => 'false',
            'rationale_text'     => 'Строгий триггер дня серии (ADR-132 Ф2): при ON день засчитывается только если игрок реально что-то сделал сегодня (старт разведки/добычи/крафта/стройки/марша = строка в character_tasks за сегодня), а не за простой вход. Точно по запросу владельца «зашёл И что-то сделал», анти-AFK. default=false → сохраняет текущее поведение ADR-108 (засчёт на первом взаимодействии) — это смена поведения ЖИВОЙ фичи, включать осознанно + анонс.',
            'effect_text'        => 'LoginStreakService::maybeReward при ON и отсутствии активности сегодня → не засчитывает день (one-shot-guard продолжает защищать от двойного засчёта). Засчёт произойдёт на следующем взаимодействии после первого действия дня. Fail-open: ошибка проверки активности не ломает серию.',
            'above_effect_text'  => 'true: серия = «дни активности», не «дни входа» — честнее и анти-AFK, но первый засчёт нового дня смещается на момент после первого действия (не на сам вход).',
            'below_effect_text'  => 'false (default): день засчитывается на первом взаимодействии дня (поведение ADR-108) — проще, но засчитывает даже пассивный вход без действий.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->where('setting_key', 'returnability.streak.strict_action_trigger')->delete();
    }
}
