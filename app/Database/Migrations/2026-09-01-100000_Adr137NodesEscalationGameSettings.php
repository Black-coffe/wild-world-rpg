<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB5 (ADR-137 «Узлы») — GameSettings гео-эскалации и кулдауна респавна узлов.
 *
 * Рычаги, которыми оперирует NodeRespawnHandler (WB5):
 *   - `world.nodes.level_step` — на сколько уровней узел становится сильнее за каждое убийство
 *     (current_level = base_level + level_step × kill_count). Default 1 = лор-точно «уровень узла =
 *     число расчисток точки».
 *   - `world.nodes.reincarnation_cap_per_band` — потолок РОСТА на конкретную точку (анти-мёртвый-
 *     хвост: южная L1-точка не дорастает до неубиваемой; юг остаётся для новичков навсегда).
 *   - `world.nodes.respawn_hours` — окно кулдауна между убийством и подъёмом узла (event-ритм мира +
 *     окно «добить вместе»). Сам кулдаун ставит kill-путь (WB8) как respawn_at = NOW + N часов;
 *     NodeRespawnHandler поднимает точку, когда respawn_at истёк.
 *
 * Дефолты — из ROADMAP §2-§3 / ADR-137; финальная калибровка — WB15. Источник правды по формуле
 * уровня — App\Services\World\NodeLevelCurve::escalatedLevel. Constitutional rule (ADR-024):
 * rationale/effect/above/below NOT NULL. Seed game_settings (НЕ createTable) → WipeManifest=KEEP.
 * Идемпотентно по setting_key.
 */
class Adr137NodesEscalationGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'world.nodes.level_step', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 1, 'default_value_text' => '1',
                'rationale_text'    => 'Гео-эскалация (ADR-137): на сколько уровней узел становится сильнее за каждое убийство — current_level = base_level + level_step × kill_count. Default 1 лор-точен: «уровень узла = сколько раз точку расчищали» (память точки, BossPointModel). Уровень пересчитывается ОТ kill_count → NodeRespawnHandler идемпотентен.',
                'effect_text'       => 'Множитель эскалации в NodeLevelCurve::escalatedLevel. Влияет на то, как быстро точка набирает уровень/HP/урон от расчистки к расчистке.',
                'above_effect_text' => 'Выше (2-3+): узел резко крепнет за пару киллов → быстро упирается в per-band cap, недолгое «новичковое» окно у точки.',
                'below_effect_text' => 'Ниже невозможно (<1 = эскалации нет, узел вечно base_level — теряется смысл «реинкарнации сильнее»).',
                'recommended_min' => 1, 'recommended_max' => 5, 'hard_min' => 0, 'hard_max' => 50,
            ],
            [
                'setting_key' => 'world.nodes.reincarnation_cap_per_band', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 40, 'default_value_text' => '40',
                'rationale_text'    => 'Потолок РОСТА на конкретную точку относительно её base_level (анти-мёртвый-хвост, ADR-137 §0.2 п8/§2). Точка не может стать сильнее, чем base_level + cap, даже после сотен расчисток → южная L1-точка дорастает максимум до L41 и остаётся доступной новичкам навсегда; нет мёртвой непроходимой L200-точки в зоне старта. Это per-точка cap, НЕ глобальный счётчик.',
                'effect_text'       => 'Верхняя граница эскалации одной точки в NodeLevelCurve::escalatedLevel (вместе с глобальным world.nodes.max_level).',
                'above_effect_text' => 'Выше: точки дорастают злее → больше вызова эндгеймеру, но риск, что южная точка станет неубиваемой для проходящих мимо новичков.',
                'below_effect_text' => 'Ниже: эскалация почти незаметна (узел недалеко уходит от старта) → killing-the-node теряет ощущение прогрессии.',
                'recommended_min' => 10, 'recommended_max' => 100, 'hard_min' => 0, 'hard_max' => 998,
            ],
            [
                'setting_key' => 'world.nodes.respawn_hours', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 12, 'default_value_text' => '12',
                'rationale_text'    => 'Кулдаун между убийством узла и его подъёмом (ADR-137). 12ч = event-ритм мира + окно «добить вместе» (со-оп «Облава»). Кулдаун выставляет kill-путь (WB8) как respawn_at = NOW + respawn_hours; NodeRespawnHandler поднимает точку, когда respawn_at истёк.',
                'effect_text'       => 'Длительность статуса cooldown точки. На сам подъём смотрит NodeRespawnHandler (respawn_at <= NOW), значение читается при записи respawn_at в момент килла.',
                'above_effect_text' => 'Выше: узлы поднимаются реже, дольше «висят» поверженными — меньше килов/день, трофей реже, но дольше окно собрать группу.',
                'below_effect_text' => 'Ниже: частые килы → спам анонсов и обесценивание трофея; <1ч = почти инстант-фарм.',
                'recommended_min' => 4, 'recommended_max' => 48, 'hard_min' => 1, 'hard_max' => 168,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
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
            'world.nodes.level_step',
            'world.nodes.reincarnation_cap_per_band',
            'world.nodes.respawn_hours',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
