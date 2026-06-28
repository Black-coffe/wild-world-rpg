<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S2 (ROADMAP-RETENTION-10, ADR-144) — гейт «скриптовой первой встречи» (NewbieGreeterService).
 *
 * Два ключа гейтят размещение дружелюбного нейтрала у спавна новичка:
 *   - greeter_enabled (killswitch, default OFF → dormant): встречающий не ставится, StartCommand
 *     byte-identical (~93 игрокам). При ON новичку у спавна материализуется один passive-нейтрал
 *     (приоритет квестгивера) → гарантированная первая встреча с NPC в первые ходы.
 *   - greeter_distance: расстояние (клеток) от спавна до встречающего; 1 = смежная клетка
 *     (виден на карте сразу, encounter в один ход).
 *
 * Ambient-фон (`npc.newbie_zone.population`/`y_min`) — отдельные, уже существующие ключи
 * (ADR-104 Ф3a); этот сервис их дополняет, не заменяет. game_settings = KEEP (WipeManifest не
 * трогаем). Idempotent. Категория world.
 */
class S2NewbieGreeterGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'npc.newbie_zone.greeter_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch S2 «скриптовой первой встречи». false (default) — DORMANT: NewbieGreeterService::placeGreeterForNewChar = no-op, StartCommand byte-identical. true — у спавна каждого нового чара (reactive, по фактической клетке Y≥900) ставится один passive-нейтрал (приоритет квестгивера, например «Мусорщик»), новичок гарантированно видит 🧑 на карте и встречает «👤 Незнакомец» в первые ходы. Бьёт в метрику S2 «% встретивших NPC в сессии-1»: ambient-population (8-15 на ~100k клеток) статистически невидима, гарантирует встречу только этот сервис. Активация после Tier-3 + решение владельца.',
                'effect_text'        => 'NewbieGreeterService::enabled гейтит хук в StartCommand (ветка нового чара).',
                'above_effect_text'  => 'true — каждый новичок получает гарантированную первую встречу с дружелюбным NPC у спавна (talk/ask/trade, NPC запоминает) → «мир обитаем» с первых минут, поддержка ретеншна сессии-1.',
                'below_effect_text'  => 'false — новичок в ghost-town (Y≥900 ≈ 2 NPC на проде против 8874 на карте), первая встреча почти не случается; текущее поведение.',
            ],
            [
                'setting_key'        => 'npc.newbie_zone.greeter_distance',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'recommended_min'    => 1,
                'recommended_max'    => 2,
                'hard_min'           => 1,
                'hard_max'           => 5,
                'rationale_text'     => 'Расстояние (в клетках) от спавна до встречающего. 1 = смежная клетка: встречающий виден на текстовой карте сразу у спавна, encounter-кнопка «👤 Незнакомец» достижима в один ход — самый надёжный путь к первой встрече. Больше — встреча дальше (новичок может уйти в другую сторону и не найти).',
                'effect_text'        => 'NewbieGreeterService::distance — смещение клетки встречающего от спавна по кандидатам-направлениям (северо-смещённым).',
                'above_effect_text'  => 'Выше — встречающий дальше от спавна; риск, что новичок (~29 ходов/сессию) уйдёт в сторону и не наткнётся → метрика встречи проседает.',
                'below_effect_text'  => 'Минимум 1 (на самой клетке спавна NPC не ставится — encounter только в смежной); 1 = максимально близко.',
            ],
        ];

        $defaults = [
            'category'        => 'world',
            'value_int'       => null,
            'value_float'     => null,
            'value_bool'      => null,
            'value_string'    => null,
            'recommended_min' => null,
            'recommended_max' => null,
            'hard_min'        => null,
            'hard_max'        => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($defaults, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'npc.newbie_zone.greeter_enabled',
            'npc.newbie_zone.greeter_distance',
        ])->delete();
    }
}
