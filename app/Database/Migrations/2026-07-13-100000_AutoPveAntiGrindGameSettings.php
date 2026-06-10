<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Анти-grind гейты авто-PvE (лог-ревью 2026-06-10).
 *
 * Прод-инцидент: чар 994 (L1, HP=1.00) на клетке легаси-спавна «Рейдер Песчаных
 * Волков» (L15, aggressive) получал 926 авто-боёв/день — AutoPveHandler крон бьёт
 * каждую минуту, floor'ит HP в 1, выхода нет; уведомление за каждый бой летело в
 * заблокировавший бота аккаунт (240 ERROR/день «bot was blocked»).
 *
 * Два tunable-гейта в AutoPveHandler:
 *   • combat.auto_pve.pair_cooldown_minutes (int, default 15; 0 = гейт выключен) —
 *     одна и та же пара (игрок, спавн) не дерётся чаще раза в N минут (cache TTL);
 *   • combat.auto_pve.skip_below_health (int, default 5; 0 = гейт выключен) —
 *     игрок с health ≤ порога (уже на полу после проигрыша) не атакуется повторно.
 *     Default 5, НЕ 1: HealthRegenerationHandler регенит +0.05/мин (level < 20) —
 *     при пороге 1 лежачий (HP=1.00) уже через минуту 1.05 > 1 → снова eligible
 *     (подтверждено Tier-3 на testbot); порог 5 даёт ~80 мин передышки на реген.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class AutoPveAntiGrindGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'combat.auto_pve.pair_cooldown_minutes',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 15,
                'default_value_text' => '15',
                'rationale_text'     => 'Анти-grind (2026-06-10): AutoPveHandler крон бьёт каждую минуту → пара (игрок, спавн) без кулдауна давала 926 боёв/день одному застрявшему L1 (вечный цикл: проигрыш → HP floor 1 → новый бой). 15 минут = опасность агрессивной клетки сохранена (бой при заходе + повтор, если стоишь), но без минутного молота.',
                'effect_text'        => 'Та же пара (игрок, npc-спавн) не вступает в авто-бой чаще одного раза в N минут (cache-кулдаун ставится перед боем). Затрагивает ТОЛЬКО авто-PvE крона (агрессивные NPC, стража руин); бой по кнопке встречи не гейтится.',
                'above_effect_text'  => 'Выше: реже авто-бои/уведомления, агрессивные клетки мягче. >60 = стража руин почти перестаёт быть угрозой для стоящего на клетке (1 бой/час), ambient-риск выхолащивается.',
                'below_effect_text'  => 'Ниже: чаще авто-бои; 0 = гейт ВЫКЛЮЧЕН (старое поведение — бой каждую минуту крона, риск grind-loop и спама уведомлений).',
                'recommended_min'    => 5,
                'recommended_max'    => 60,
                'hard_min'           => 0,
                'hard_max'           => 1440,
            ],
            [
                'setting_key'        => 'combat.auto_pve.skip_below_health',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Анти-grind (2026-06-10): проигравший авто-бой floor\'ится в HP=1 и без лечения проигрывает каждый следующий бой вечно (926 боёв/день у чара 994). Игрок на полу HP уже побеждён — добивать бессмысленно (наград нет, только спам и grind-цикл). Порог 5, НЕ 1: HealthRegenerationHandler регенит +0.05 HP/мин (level<20) — при пороге 1 лежачий через минуту 1.05>1 и снова в бою (остаётся только кулдаун); порог 5 даёт ~80 мин передышки (реген 1→5).',
                'effect_text'        => 'AutoPveHandler пропускает игрока целиком, если его health ≤ порога. Лежачий/ослабленный игрок не получает авто-бой и уведомление; отрегенился выше порога → снова уязвим для агрессивных NPC. Вместе с кулдауном пары: застрявший на агрессивной клетке L<20 дерётся ~раз в 80 мин (а не каждую минуту).',
                'above_effect_text'  => 'Выше (напр. 10-20): авто-PvE не трогает всё более здоровых игроков — агрессивные NPC и стража руин теряют угрозу (ходить по опасным клеткам с пол-HP безнаказанно). Передышка лежачего растёт на ~20 мин за каждый +1 порога (реген 0.05/мин).',
                'below_effect_text'  => 'Ниже (1): из-за регена 0.05/мин лежачий снова eligible через минуту — частоту боёв ограничивает только кулдаун пары (~96 боёв/день при 15 мин). 0 = гейт ВЫКЛЮЧЕН (старое поведение — молот каждую минуту крона).',
                'recommended_min'    => 1,
                'recommended_max'    => 10,
                'hard_min'           => 0,
                'hard_max'           => 50,
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $keys = [
            'combat.auto_pve.pair_cooldown_minutes',
            'combat.auto_pve.skip_below_health',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
