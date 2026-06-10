<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-106 — настройки уникальных L30+ боссов глубокого севера (RaiderGradientHandler).
 *
 * Боссы поддерживаются тем же кроном, что и градиент рейдеров (ADR-105), но под
 * ОТДЕЛЬНЫМ killswitch'ем (dormant-first): мир получает боссов только после явной
 * активации. По умолчанию — по 1 живому экземпляру каждого в зоне Y < y_max (150).
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class NorthBossesGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.bosses.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-106: killswitch уникальных L30+ боссов глубокого севера (Шрам L30 / Цербер L32 / Патриарх Стаи L35). default=false → dormant: RaiderGradientHandler не спавнит боссов, контент в npcs лежит мёртвым. Отдельный от world.raiders.gradient_enabled — боссы активируются после наблюдения градиента.',
                'effect_text'        => 'При ON крон npc.raider-gradient поддерживает по population_each живых экземпляров каждого босса в зоне Y < world.bosses.y_max. Боссы aggressive → AutoPveHandler авто-бьёт стоящего на клетке (анти-grind гейты v0.51.396 прикрывают). Победа над боссом ≥ уровня игрока даёт сильный пакет наград RewardService.',
                'above_effect_text'  => 'true: глубокий север получает уникальных хозяев — вызов и цель для L25+; убитый босс возрождается в новой случайной клетке зоны (его надо искать заново).',
                'below_effect_text'  => 'false: боссов в мире нет (dormant). Emergency-off: живые экземпляры НЕ удаляются автоматически (крон просто перестаёт поддерживать; добить можно вручную SQL).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'world.bosses.population_each',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 1,
                'default_value_text' => '1',
                'rationale_text'     => 'ADR-106: живых экземпляров КАЖДОГО босса одновременно. 1 = уникальность («единственный в своём роде» — лор /tips и смысл контента): в мире ровно один Шрам, один Цербер, один Патриарх.',
                'effect_text'        => 'Крон держит столько живых спавнов каждого из 3 боссов в зоне Y < y_max. Убитый — возрождается следующим тиком в новой случайной клетке (поиск заново = анти-фарм).',
                'above_effect_text'  => 'Выше (2-3): боссы перестают быть уникальными, чаще встречи и фарм наград; ломает лор «единственный в своём роде». >3 = север превращается в боссо-ферму.',
                'below_effect_text'  => 'Ниже (0): тир боссов выключен (живые экземпляры будут срезаны кроном по плану зоны).',
                'recommended_min'    => 0,
                'recommended_max'    => 1,
                'hard_min'           => 0,
                'hard_max'           => 10,
            ],
            [
                'setting_key'        => 'world.bosses.y_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 150,
                'default_value_text' => '150',
                'rationale_text'     => 'ADR-106: южная граница (Y, эксклюзивно) зоны боссов. 150 = самая кромка мира, та же полоса, где руины (Y69-162) — максимум риска там же, где максимум ценности (канон ADR-101 «север=опаснее»). Спавн взвешен к северу → фактически боссы живут в Y0-99.',
                'effect_text'        => 'Боссы спавнятся только в Y < этого значения (внутри — вес к северу, как у тиров ADR-105). Спавны южнее границы крон удаляет (zone-purge).',
                'above_effect_text'  => 'Выше (напр. 300): боссы спускаются к средне-северному поясу — внезапные встречи для неготовых L15-25.',
                'below_effect_text'  => 'Ниже (напр. 80): боссы только у самой кромки — найти сложнее, контент реже встречается.',
                'recommended_min'    => 80,
                'recommended_max'    => 300,
                'hard_min'           => 50,
                'hard_max'           => 850,
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
        $keys = ['world.bosses.enabled', 'world.bosses.population_each', 'world.bosses.y_max'];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
