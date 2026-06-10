<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-104 Фаза 3 — «момент удачи + первая встреча NPC у новичковых клеток».
 *
 * Ф3a (спавн дружных NPC в новичковой зоне Y≥900, реюз WandererSpawnHandler):
 *   • npc.newbie_zone.population (int, default 0 → DORMANT) — целевая популяция
 *     passive-нейтралов в южной новичковой зоне;
 *   • npc.newbie_zone.y_min (int, default 900) — нижняя граница Y зоны.
 *
 * Ф3b (гарантированный «момент удачи» на первый ход новичка, LuckyFindService):
 *   • onboarding.lucky_find.enabled (bool, default false → DORMANT) — killswitch;
 *   • onboarding.lucky_find.gold (int, default 300) — золото находки;
 *   • onboarding.lucky_find.ironstone (int, default 5) — Железная руда (id 72) находки.
 *
 * Бьёт по прод-разрыву №1 (E1/E5): новичковая зона Y≥900 = NPC-привид (спавн
 * блукачей Y401-900 НЕ пересекается); первая сессия = таймеры без событий.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class ADR104Phase3GameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'npc.newbie_zone.population',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'ADR-104 Ф3a: целевая популяция passive-нейтралов (блукачей) в новичковой зоне Y≥900. default=0 → dormant: WandererSpawnHandler НЕ делает второй проход для южной зоны. Закрывает разрыв №1 — новичок спавнится Y≥900, а обычные блукачи Y401-900 → зоны не пересекаются, новичок не встречает NPC (Y900-band: 21 alive vs 1999 на Y800).',
                'effect_text'        => 'При >0 WandererSpawnHandler топит до этого числа живых passive-нейтралов в клетках Y≥y_min (реюз пула, безопасно — AutoPveHandler пропускает passive). Делает первую встречу с NPC возможной для новичка (talk/ask/trade-лор через NpcEncounterAction).',
                'above_effect_text'  => 'Выше: больше NPC в новичковой зоне, чаще встречи, живее юг. >15-20 = перенаселение южной зоны, встречи на каждой клетке (теряется ценность). 10/тик cap на налив.',
                'below_effect_text'  => 'Ниже (0): новичковая зона без блукачей (dormant), первая встреча с NPC недоступна пока новичок не уйдёт севернее Y900. Emergency-off.',
                'recommended_min'    => 0,
                'recommended_max'    => 15,
                'hard_min'           => 0,
                'hard_max'           => 100,
            ],
            [
                'setting_key'        => 'npc.newbie_zone.y_min',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 900,
                'default_value_text' => '900',
                'rationale_text'     => 'ADR-104 Ф3a: нижняя граница Y новичковой зоны для спавна блукачей. 900 = совпадает с зоной спавна новичка (StartCommand: Y≥900) и safe-fallback респавна (PlayerRespawner Y900-999).',
                'effect_text'        => 'WandererSpawnHandler во втором проходе берёт клетки coordinate_y ≥ этого значения (биомы 1-3,5-9). Граница новичковой зоны.',
                'above_effect_text'  => 'Выше (напр. 950): зона уже, ближе к самому югу — меньше клеток, новичок может оказаться чуть севернее зоны.',
                'below_effect_text'  => 'Ниже (напр. 850): зона шире, захватывает часть среднего пояса — может пересечься с обычным спавном Y401-900 (дублирование).',
                'recommended_min'    => 850,
                'recommended_max'    => 950,
                'hard_min'           => 401,
                'hard_max'           => 999,
            ],
            [
                'setting_key'        => 'onboarding.lucky_find.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-104 Ф3b: killswitch гарантированного «момента удачи» — one-shot находка на ПЕРВЫЙ ход новичка (LuckyFindService, хук в MoveCharacterToDirectionAction рядом с подсказкой Слоя 1). default=false → dormant. Триггер именно на движение — прямой удар по «58% новичков без единого хода»: награда за действие, которого мы хотим.',
                'effect_text'        => 'При ON: первый move новичка (level ≤ contextual_hints.max_level) даёт one-shot бонус (золото + Железная руда) + флавор-сообщение «нашёл тайник»; флаг LUCKY_FIND_FIRST в action_log (idempotent). Повторные ходы — no-op.',
                'above_effect_text'  => 'true: каждый новичок при первом ходе получает находку → движение становится мгновенно вознаграждаемым, закрепляя поведение (operant). Усиливает шаг цепочки ADR-103 «сделай первую вылазку».',
                'below_effect_text'  => 'false: первый ход без бонуса (dormant), как сейчас. Emergency-off.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'onboarding.lucky_find.gold',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 300,
                'default_value_text' => '300',
                'rationale_text'     => 'ADR-104 Ф3b: золото в «находке» на первый ход. 300 при стартовых 1000 = заметный, но не ломающий бонус (≈первая мелкая покупка). Лор: тайник выжившего среди обломков.',
                'effect_text'        => 'LuckyFindService добавляет это золото (increaseGold) при первой находке.',
                'above_effect_text'  => 'Выше: щедрее старт, но >1000 = инфляция раннего золота, обесценивает первые продажи.',
                'below_effect_text'  => 'Ниже (0): золото в находку не кладём (только ресурс, если задан).',
                'recommended_min'    => 0,
                'recommended_max'    => 1000,
                'hard_min'           => 0,
                'hard_max'           => 100000,
            ],
            [
                'setting_key'        => 'onboarding.lucky_find.ironstone',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'ADR-104 Ф3b: количество Железной руды (resource id 72, rarity 7, level_required 2) в «находке». Ощущается как удача — это материал, который новичок НЕ может добыть на L1 (нужен ур.2); даёт фору на крафт/продажу. 5 ед. — скромно.',
                'effect_text'        => 'LuckyFindService выдаёт это число Железной руды (addOrIncreaseResource) при первой находке.',
                'above_effect_text'  => 'Выше: больше ценного материала на старте, быстрее первый продвинутый крафт. >50 = заметная инъекция редкого ресурса.',
                'below_effect_text'  => 'Ниже (0): руду в находку не кладём (только золото).',
                'recommended_min'    => 0,
                'recommended_max'    => 20,
                'hard_min'           => 0,
                'hard_max'           => 500,
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
            'npc.newbie_zone.population',
            'npc.newbie_zone.y_min',
            'onboarding.lucky_find.enabled',
            'onboarding.lucky_find.gold',
            'onboarding.lucky_find.ironstone',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
