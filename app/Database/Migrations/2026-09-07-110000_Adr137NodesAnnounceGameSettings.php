<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB11 (ADR-137 «Узлы») — GameSettings глобального анонса о повергнутых узлах.
 *
 *  - `world.nodes.announce_enabled` — мастер-killswitch анонса (дайджест + enqueue). OFF → kill-путь
 *    не пишет в очередь, дайджест-крон no-op.
 *  - `world.nodes.announce_min_level` — только значимые килы попадают в сводку (L1-99 не глобалят →
 *    анти-спам, ADR-137 §0.3).
 *  - `world.nodes.announce_digest_hour` — час серверного времени для рассылки «Сводки с пустоши»
 *    (раз в сутки, паттерн DailyTipBroadcastHandler).
 *
 * Дефолты — стартовые (ROADMAP §3 / ADR-137); финал — WB15/WB16. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings (НЕ createTable) → WipeManifest=KEEP.
 * Идемпотентно по setting_key.
 */
class Adr137NodesAnnounceGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'world.nodes.announce_enabled', 'value_type' => 'bool', 'value_bool' => 0,
                'value_int' => null, 'default_value_text' => '0',
                'rationale' => 'Мастер-killswitch глобального анонса узлов (ADR-137 WB11). OFF (dormant): kill-путь НЕ пишет в boss_kill_announce_queue, дайджест-крон no-op. ON: квалифицирующие килы копятся в очередь, раз в сутки уходит обезличенная «Сводка с пустоши».',
                'effect' => 'Включает enqueue киллов + ежедневный дайджест-broadcast.',
                'above' => 'ON: игроки получают раз-в-сутки сводку о павших высокоуровневых узлах (FOMO/ритм мира).',
                'below' => 'OFF: анонсов нет, мир «молчит» о падении узлов.',
                'rmin' => 0, 'rmax' => 1, 'hmin' => 0, 'hmax' => 1,
            ],
            [
                'setting_key' => 'world.nodes.announce_min_level', 'value_type' => 'int', 'value_int' => 100,
                'value_bool' => null, 'default_value_text' => '100',
                'rationale' => 'Порог уровня узла, с которого килл попадает в сводку (ADR-137 §0.3). Анонсим ТОЛЬКО значимое: L1-99 (массовые южные/центровые килы) не глобалят → канал не тонет в спаме мелкими событиями.',
                'effect' => 'Минимальный boss_level для enqueue в очередь анонсов.',
                'above' => 'Выше: в сводку попадают только топ-узлы → анонсов почти нет (тишина).',
                'below' => 'Ниже: сводка раздувается мелкими килами → шум, обесценивание значимых событий.',
                'rmin' => 50, 'rmax' => 180, 'hmin' => 1, 'hmax' => 998,
            ],
            [
                'setting_key' => 'world.nodes.announce_digest_hour', 'value_type' => 'int', 'value_int' => 11,
                'value_bool' => null, 'default_value_text' => '11',
                'rationale' => 'Час серверного времени для ежедневной рассылки «Сводки с пустоши» (ADR-137 WB11). Раз в сутки (once/day-claim, как DailyTipBroadcastHandler). 11:00 разнесён с «Советом дня» (10:00), чтобы два broadcast не сливались в один поток.',
                'effect' => 'В этот час дайджест-крон собирает pending-килы и рассылает сводку opted-in игрокам.',
                'above' => 'Позже днём — сводка приходит вечером (зависит от активности аудитории).',
                'below' => 'Раньше — сводка приходит утром; 0-6 = ночью (низкая видимость).',
                'rmin' => 8, 'rmax' => 22, 'hmin' => 0, 'hmax' => 23,
            ],
        ];

        foreach ($rows as $r) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $r['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert([
                'setting_key' => $r['setting_key'], 'category' => 'world', 'value_type' => $r['value_type'],
                'value_int' => $r['value_int'] ?? null, 'value_bool' => $r['value_bool'] ?? null,
                'default_value_text' => $r['default_value_text'],
                'rationale_text' => $r['rationale'], 'effect_text' => $r['effect'],
                'above_effect_text' => $r['above'], 'below_effect_text' => $r['below'],
                'recommended_min' => $r['rmin'], 'recommended_max' => $r['rmax'],
                'hard_min' => $r['hmin'], 'hard_max' => $r['hmax'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', [
            'world.nodes.announce_enabled', 'world.nodes.announce_min_level', 'world.nodes.announce_digest_hour',
        ])->delete();
    }
}
