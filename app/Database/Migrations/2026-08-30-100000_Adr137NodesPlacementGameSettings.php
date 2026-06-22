<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WB4 (ADR-137 «Узлы») — GameSettings размещения и гео-кривой узлов.
 *
 * Мастер-killswitch point-модели + количество точек + параметры гео-старт-уровня и HP-кривой
 * (дефолты из ROADMAP §2, источник правды — App\Services\World\NodeLevelCurve). Полная
 * калибровка кривой — WB15. Constitutional rule (ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest=KEEP. Идемпотентно по setting_key.
 */
class Adr137NodesPlacementGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key' => 'world.nodes.point_mode_enabled', 'category' => 'world',
                'value_type' => 'bool', 'value_bool' => 0, 'default_value_text' => 'false',
                'rationale_text'    => 'Мастер-killswitch point-модели «Узлов» (ADR-137). OFF = старое кочующее поведение боссов ADR-106 (RaiderGradientHandler спавнит трио в случайных Y<150). ON = боссы живут как фиксированные точки boss_points (NodeRespawnHandler материализует/эскалирует). Default OFF — dormant-first, активация поэтапно в WB16.',
                'effect_text'       => 'Переключает источник боссов: точки-узлы (boss_points) против кочующего трио. При ON RaiderGradientHandler-блок боссов уступает NodeRespawnHandler.',
                'above_effect_text' => 'ON: 24 фикс-точки активны; кочующее трио отключено (двойного спавна нет).',
                'below_effect_text' => 'OFF (default): узлы спят, боссы — как в ADR-106 (кочующие, под world.bosses.enabled).',
                'recommended_min' => 0, 'recommended_max' => 1, 'hard_min' => 0, 'hard_max' => 1,
            ],
            [
                'setting_key' => 'world.nodes.point_count', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 24, 'default_value_text' => '24',
                'rationale_text'    => 'Сколько узлов на карте (решение владельца 2026-06-22): 24, разброс по всей карте, скопление центр+север. Seed размещает ровно столько валидных точек (не вода/край/чужой claim).',
                'effect_text'       => 'Целевое число точек-узлов. Seed-миграция и (позже) обслуживающий крон ориентируются на это число.',
                'above_effect_text' => 'Выше: больше узлов → плотнее эндгейм-контент, но риск перенасыщения карты и нагрузки на анонс/крон.',
                'below_effect_text' => 'Ниже: реже встречаются, узлы становятся редким «событием»; <10 — почти незаметны.',
                'recommended_min' => 8, 'recommended_max' => 48, 'hard_min' => 1, 'hard_max' => 200,
            ],
            [
                'setting_key' => 'world.nodes.south_base_level', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 1, 'default_value_text' => '1',
                'rationale_text'    => 'Стартовый уровень узла на крайнем юге (Y=999, зона новичков). L1 = «валит кто угодно с правильным шмотом» (соло-DPS 30-60 vs HP 108). Держит юг доступным новичкам навсегда.',
                'effect_text'       => 'Нижний якорь гео-кривой base_level (NodeLevelCurve::baseLevelForY).',
                'above_effect_text' => 'Выше: южные узлы перестают быть новичковыми (стена на старте).',
                'below_effect_text' => 'Ниже 1 невозможно (клампится в 1).',
                'recommended_min' => 1, 'recommended_max' => 10, 'hard_min' => 1, 'hard_max' => 50,
            ],
            [
                'setting_key' => 'world.nodes.north_base_level', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 50, 'default_value_text' => '50',
                'rationale_text'    => 'Стартовый уровень узла на крайнем севере (Y=0, пояс опасности/руин). Верхний якорь гео-кривой; от него эскалация растёт к per-band cap. 50 = серьёзный вызов для мида/эндгейма, но не уаншот (см. combat.nodes.leveldiff_cap).',
                'effect_text'       => 'Верхний якорь гео-кривой base_level. Между ним и south_base_level — линейная интерполяция по широте.',
                'above_effect_text' => 'Выше: северные узлы стартуют злее → раньше упираются в потолок, мёртвый хвост (некому бить соло).',
                'below_effect_text' => 'Ниже: север теряет опасность, эндгеймеру скучно.',
                'recommended_min' => 20, 'recommended_max' => 90, 'hard_min' => 1, 'hard_max' => 180,
            ],
            [
                'setting_key' => 'world.nodes.max_level', 'category' => 'world',
                'value_type' => 'int', 'value_int' => 180, 'default_value_text' => '180',
                'rationale_text'    => 'Потолок уровня узла v1 (решение владельца): под ~147 reachable / 3-8 async-участников «Облавы». L500-999 + клан-рейд отрезаны до клан-механики (отдельная веха).',
                'effect_text'       => 'Жёсткий потолок current_level/base_level. Эскалация (WB5) не поднимает выше.',
                'above_effect_text' => 'Выше: верхние узлы становятся непроходимы текущей аудиторией → мёртвый хвост.',
                'below_effect_text' => 'Ниже: меньше вызова эндгеймеру, быстрее упор в потолок.',
                'recommended_min' => 100, 'recommended_max' => 250, 'hard_min' => 10, 'hard_max' => 999,
            ],
            [
                'setting_key' => 'combat.nodes.hp_seg1_base', 'category' => 'combat',
                'value_type' => 'int', 'value_int' => 100, 'default_value_text' => '100',
                'rationale_text'    => 'База HP-кривой узла (§2): HP = base + level*seg1_per на L1-50. 100 → L1=108 (соло за 6-15 раундов floor-урона).',
                'effect_text'       => 'Аддитивная база HP всех узлов (NodeLevelCurve::healthForLevel).',
                'above_effect_text' => 'Выше: даже L1-узлы становятся губками — дольше соло-бой на юге.',
                'below_effect_text' => 'Ниже: низкие узлы падают в пару ударов, теряется ощущение боя.',
                'recommended_min' => 50, 'recommended_max' => 300, 'hard_min' => 10, 'hard_max' => 5000,
            ],
            [
                'setting_key' => 'combat.nodes.hp_seg1_per_level', 'category' => 'combat',
                'value_type' => 'int', 'value_int' => 8, 'default_value_text' => '8',
                'rationale_text'    => 'Прирост HP за уровень на L1-50 (§2): L50=500. Пологий рост — соло-проходимо до мида.',
                'effect_text'       => 'Наклон HP-кривой в первом сегменте (L≤50).',
                'above_effect_text' => 'Выше: мид-узлы быстро превращаются в губки.',
                'below_effect_text' => 'Ниже: уровень почти не добавляет живучести.',
                'recommended_min' => 4, 'recommended_max' => 20, 'hard_min' => 1, 'hard_max' => 200,
            ],
            [
                'setting_key' => 'combat.nodes.hp_seg1_cap_level', 'category' => 'combat',
                'value_type' => 'int', 'value_int' => 50, 'default_value_text' => '50',
                'rationale_text'    => 'Граница сегментов HP-кривой (§2): до L50 — пологий seg1, выше — крутой seg2 (нужна группа). Точка перехода «соло → кооп».',
                'effect_text'       => 'Уровень переключения с seg1_per на seg2_per в HP-кривой.',
                'above_effect_text' => 'Выше: соло-зона тянется дальше на север.',
                'below_effect_text' => 'Ниже: кооп-стена начинается раньше (узлы быстрее становятся групповыми).',
                'recommended_min' => 30, 'recommended_max' => 80, 'hard_min' => 1, 'hard_max' => 180,
            ],
            [
                'setting_key' => 'combat.nodes.hp_seg2_per_level', 'category' => 'combat',
                'value_type' => 'int', 'value_int' => 40, 'default_value_text' => '40',
                'rationale_text'    => 'Прирост HP за уровень на L50-180 (§2): L150=4500. Крутой рост = математический гейт «нужна группа» (вместе с реген-гейтом WB7).',
                'effect_text'       => 'Наклон HP-кривой во втором сегменте (L>50).',
                'above_effect_text' => 'Выше: верхние узлы требуют больше участников «Облавы».',
                'below_effect_text' => 'Ниже: высокие узлы проходимы меньшей группой/соло терпением.',
                'recommended_min' => 20, 'recommended_max' => 80, 'hard_min' => 1, 'hard_max' => 500,
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
            'world.nodes.point_mode_enabled', 'world.nodes.point_count',
            'world.nodes.south_base_level', 'world.nodes.north_base_level', 'world.nodes.max_level',
            'combat.nodes.hp_seg1_base', 'combat.nodes.hp_seg1_per_level',
            'combat.nodes.hp_seg1_cap_level', 'combat.nodes.hp_seg2_per_level',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
