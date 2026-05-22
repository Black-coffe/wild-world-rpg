<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * D «двойная правда» (scoped) — live-tunable скаляры зданий из Config\GameBalance
 * → GameSettings (constitutional admin-tunable, ADR-024). category=buildings. Idempotent.
 *
 * Мигрируются ИМЕННО те значения, которые админ реально live-тюнит (без редеплоя):
 *  - gym tick-interval (частота прибавки силы);
 *  - handpump biome-множители (6 биомов);
 *  - greenhouse water-shortage cooldown + threshold (анти-спам уведомлений).
 *
 * Прогрессия-кривые по уровням (greenhouseLevels / gymStrengthByLevel / handPumpLevels)
 * ОСТАЮТСЯ в Config\GameBalance как code-owned design (меняются через ревью, не live).
 * Значения здесь = текущие GameBalance-дефолты 1:1 (0 изменения баланса). Хендлеры читают
 * GameSettings с fallback на GameBalance → обратная совместимость.
 */
class DSeedBuildingScalarBalanceGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'building.gym.tick_interval_minutes',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Раз во сколько минут Спортзал даёт прибавку к силе. 30 — раз в полчаса (info-текст здания обещает именно «каждые 30 минут»). Меньше = чаще = быстрее качается сила.',
                'effect_text'        => 'GymProductionHandler тикает (начисляет силу) только когда минута кратна этому значению.',
                'above_effect_text'  => 'При 120 (раз в 2ч) прибавка силы ползёт втрое медленнее → Спортзал почти бесполезен.',
                'below_effect_text'  => 'При 5 (как врал старый текст) сила растёт в 6× быстрее → быстрый stat-creep, нужен пересчёт gymStrengthByLevel.',
                'recommended_min'    => '5',
                'recommended_max'    => '120',
                'hard_min'           => '1',
                'hard_max'           => '1440',
            ],
            [
                'setting_key'        => 'building.greenhouse.water_shortage_threshold',
                'value_type'         => 'int',
                'value_int'          => 3,
                'default_value_text' => '3',
                'rationale_text'     => 'Порог воды (≤), ниже которого Теплица шлёт владельцу предупреждение «мало воды». 3 — заранее, пока производство ещё идёт.',
                'effect_text'        => 'GreenhouseProductionHandler шлёт warning, когда остаток воды ≤ этого значения (с cooldown).',
                'above_effect_text'  => 'При 50 владельца спамит предупреждениями почти всегда → шум.',
                'below_effect_text'  => 'При 0 предупреждение приходит только когда вода кончилась → поздно, теплица уже встала.',
                'recommended_min'    => '0',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '1000',
            ],
            [
                'setting_key'        => 'building.greenhouse.water_shortage_cooldown_sec',
                'value_type'         => 'int',
                'value_int'          => 1800,
                'default_value_text' => '1800',
                'rationale_text'     => 'Минимум секунд между повторными предупреждениями Теплицы о нехватке воды (анти-спам). 1800 = 30 минут.',
                'effect_text'        => 'GreenhouseProductionHandler не шлёт повторный water-warning в пределах этого окна.',
                'above_effect_text'  => 'При очень большом владелец получит одно предупреждение и пропустит затяжную нехватку.',
                'below_effect_text'  => 'При 0 каждый тик при низкой воде = новое уведомление → спам, владелец отключит бота.',
                'recommended_min'    => '300',
                'recommended_max'    => '7200',
                'hard_min'           => '0',
                'hard_max'           => '86400',
            ],
        ];

        // 6 биом-множителей HandPump (производство воды).
        $biomes = [
            'wet'      => [1.2, 'влажный биом — воды больше всего'],
            'cold'     => [0.9, 'холодный биом — воды чуть меньше'],
            'dry'      => [0.5, 'засушливый биом — воды вдвое меньше'],
            'volcanic' => [0.7, 'вулканический биом — воды меньше'],
            'cave'     => [0.6, 'пещеры — воды мало'],
            'plain'    => [1.0, 'равнина — базовый множитель'],
        ];
        foreach ($biomes as $biome => [$val, $desc]) {
            $rows[] = [
                'setting_key'        => 'building.handpump.biome_multiplier.' . $biome,
                'value_type'         => 'float',
                'value_float'        => $val,
                'default_value_text' => (string) $val,
                'rationale_text'     => "Множитель добычи воды Ручной скважины в биоме «{$biome}» ({$desc}). Базовый выход × это.",
                'effect_text'        => 'HandPumpProductionHandler множит базовую добычу воды (по уровню) на множитель биома текущей клетки базы.',
                'above_effect_text'  => 'При 3.0+ скважина в этом биоме заваливает водой → инфляция воды.',
                'below_effect_text'  => 'При 0 (или очень мало) скважина в этом биоме почти не даёт воды.',
                'recommended_min'    => '0.3',
                'recommended_max'    => '2.0',
                'hard_min'           => '0',
                'hard_max'           => '5',
            ];
        }

        $defaults = [
            'category'        => 'buildings',
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
            'building.gym.tick_interval_minutes',
            'building.greenhouse.water_shortage_threshold',
            'building.greenhouse.water_shortage_cooldown_sec',
            'building.handpump.biome_multiplier.wet',
            'building.handpump.biome_multiplier.cold',
            'building.handpump.biome_multiplier.dry',
            'building.handpump.biome_multiplier.volcanic',
            'building.handpump.biome_multiplier.cave',
            'building.handpump.biome_multiplier.plain',
        ])->delete();
    }
}
