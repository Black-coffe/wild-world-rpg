<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-105 — градиент опасности рейдеров «север = опаснее» (RaiderGradientHandler).
 *
 * Контекст: легаси-посев 2025-02-18 дал плотность рейдеров, растущую К ЮГУ
 * (Y800 ≈ 1994 vs Y100 ≈ 346) — обратно канон-принципу «север=опаснее» (ADR-101);
 * L20 «Главарь Песчаных Волков» и L25 «Гладиатор пустоши» существовали в npcs,
 * но имели 0 живых спавнов. Крон поддерживает целевые популяции трёх hostile-тиров
 * по Y-бэндам с линейным весом к северу + перманентно чистит Y >= y_cutoff
 * (новичковый пояс) + ограничивает скорость перестройки per-tick.
 *
 * Constitutional rule (CLAUDE.md §🎛️ ADR-024): rationale/effect/above/below NOT NULL.
 * Seed game_settings (НЕ createTable) → WipeManifest не затрагивается (KEEP). Идемпотентно.
 */
class RaiderGradientGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.raiders.gradient_enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'ADR-105: killswitch крона RaiderGradientHandler (градиент «север=опаснее»). default=false → dormant: крон no-op, мир не меняется. Включается после Tier-3 на testbot и прод-наблюдения первых тиков.',
                'effect_text'        => 'При ON крон everyMinute: (1) чистит рейдеров на Y>=y_cutoff (новичковый пояс), (2) поддерживает популяции тиров L15/L20/L25 по Y-бэндам с линейным весом к северу, (3) не более max_adjust_per_tick операций за тик. Редистрибуция текущих ~8.8k рейдеров займёт ~1 час.',
                'above_effect_text'  => 'true: распределение рейдеров постепенно перестраивается к северному градиенту и далее поддерживается кроном (популяция больше не распадается от фарма безвозвратно).',
                'below_effect_text'  => 'false: dormant, статус-кво (плотность к югу, L20/L25 не спавнятся, популяция только тает). Emergency-off: текущие спавны остаются как есть.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
            ],
            [
                'setting_key'        => 'world.raiders.population_l15',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 7000,
                'default_value_text' => '7000',
                'rationale_text'     => 'ADR-105: целевая популяция базового тира — «Рейдер Песчаных Волков» (npc_id=1, L15), весь диапазон Y0..y_cutoff. 7000 + 1200 (L20) + 600 (L25) = 8800 ≈ статус-кво (8774 живых на 2026-06-10) — ребаланс меняет ФОРМУ распределения, не объём контента.',
                'effect_text'        => 'Крон держит столько живых L15-рейдеров суммарно, распределяя по Y-бэндам с линейным весом к северу (Y0-99 самый плотный). Это основная масса PvE-целей мира (npc_kills квесты, лут 501).',
                'above_effect_text'  => 'Выше: плотнее весь мир, чаще встречи с агрессорами на каждом ярусе. >12000 = заметно теснее даже на юге.',
                'below_effect_text'  => 'Ниже: реже PvE-цели, мир пустеет; 0 = тир выключен (крон срежет всех L15 по бэндам с юга).',
                'recommended_min'    => 3000,
                'recommended_max'    => 12000,
                'hard_min'           => 0,
                'hard_max'           => 50000,
            ],
            [
                'setting_key'        => 'world.raiders.population_l20',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 1200,
                'default_value_text' => '1200',
                'rationale_text'     => 'ADR-105: целевая популяция среднего тира — «Главарь Песчаных Волков» (npc_id=2, L20, лут 502), зона Y0..l20_y_max. До сих пор 0 спавнов (контент существовал мёртвым). 1200 = ~14% общей популяции — север/середина ощутимо опаснее, но базовый тир доминирует.',
                'effect_text'        => 'Крон держит столько живых L20 в зоне Y < l20_y_max с весом к северу. Поднимает фактическую опасность середины и севера (состав, не только плотность).',
                'above_effect_text'  => 'Выше: середина карты заметно жёстче для L15-20 игроков, медленнее продвижение на север. >3000 = L20 чаще L15 в своих бэндах.',
                'below_effect_text'  => 'Ниже: середина мягче; 0 = тир выключен (крон срежет всех L20).',
                'recommended_min'    => 0,
                'recommended_max'    => 3000,
                'hard_min'           => 0,
                'hard_max'           => 20000,
            ],
            [
                'setting_key'        => 'world.raiders.population_l25',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 600,
                'default_value_text' => '600',
                'rationale_text'     => 'ADR-105: целевая популяция элитного тира — «Гладиатор пустоши» (npc_id=3, L25, лут 601), только глубокий север Y0..l25_y_max. 600 = редкая элита (~7% популяции): глубокий север — самое опасное место мира (канон ADR-101), но не стена.',
                'effect_text'        => 'Крон держит столько живых L25 в зоне Y < l25_y_max с весом к северу. Делает глубокий север территорией высокого риска/ценности.',
                'above_effect_text'  => 'Выше: глубокий север жёстче, поход к руинам (Y69-162) опаснее. >1500 = север почти непроходим для <L20.',
                'below_effect_text'  => 'Ниже: север мягче, элита реже; 0 = тир выключен.',
                'recommended_min'    => 0,
                'recommended_max'    => 1500,
                'hard_min'           => 0,
                'hard_max'           => 10000,
            ],
            [
                'setting_key'        => 'world.raiders.l20_y_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 600,
                'default_value_text' => '600',
                'rationale_text'     => 'ADR-105: южная граница (Y, эксклюзивно) зоны L20 «Главарей». 600 = север + середина: игрок встречает L20 после освоения южных ярусов, градиент состава ступенчатый (юг L15 → середина +L20 → глубокий север +L25).',
                'effect_text'        => 'L20 спавнятся только в Y < этого значения. Граница ступени «середина опаснее юга».',
                'above_effect_text'  => 'Выше (напр. 800): L20 заходят ближе к южным ярусам — раньше встречаются игрокам низких уровней.',
                'below_effect_text'  => 'Ниже (напр. 400): L20 только на севере, середина остаётся чисто L15.',
                'recommended_min'    => 300,
                'recommended_max'    => 800,
                'hard_min'           => 100,
                'hard_max'           => 850,
            ],
            [
                'setting_key'        => 'world.raiders.l25_y_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 300,
                'default_value_text' => '300',
                'rationale_text'     => 'ADR-105: южная граница (Y, эксклюзивно) зоны L25 «Гладиаторов». 300 = глубокий север: элита там же, где самые ценные статические объекты (руины Y69-162) — риск соответствует награде (канон ADR-101).',
                'effect_text'        => 'L25 спавнятся только в Y < этого значения. Граница ступени «глубокий север — максимум опасности».',
                'above_effect_text'  => 'Выше (напр. 500): элита спускается к середине — жёстче для среднеуровневых.',
                'below_effect_text'  => 'Ниже (напр. 150): элита только у самых руин, остальной север мягче.',
                'recommended_min'    => 150,
                'recommended_max'    => 500,
                'hard_min'           => 50,
                'hard_max'           => 850,
            ],
            [
                'setting_key'        => 'world.raiders.y_cutoff',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 850,
                'default_value_text' => '850',
                'rationale_text'     => 'ADR-105: с этого Y и южнее рейдеров НЕТ — перманентная гарантия чистоты новичкового пояса (замена разовой чистки 2026-06-10: крон сам удаляет залётных). 850 = буфер 50 строк перед новичковой зоной спавна Y>=900.',
                'effect_text'        => 'Крон каждый тик удаляет живых рейдеров тиров (npc_id 1/2/3) на Y >= этого значения; все целевые зоны тиров обрезаются этим cutoff. Новичок (спавн Y>=900) гарантированно не встречает агрессоров в первых ходах.',
                'above_effect_text'  => 'Выше (напр. 900): буфер уже, рейдеры ближе к новичкам — растёт риск повторения grind-инцидента чара 994 (см. v0.51.396).',
                'below_effect_text'  => 'Ниже (напр. 800): буфер шире, юг безопаснее/пустее для L1-5.',
                'recommended_min'    => 800,
                'recommended_max'    => 900,
                'hard_min'           => 500,
                'hard_max'           => 1000,
            ],
            [
                'setting_key'        => 'world.raiders.max_adjust_per_tick',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'rationale_text'     => 'ADR-105: лимит операций (insert + delete суммарно) за тик крона. 200/мин = первичная редистрибуция ~8.8k спавнов размазывается на ~1 час — без шока мира и без тяжёлого тика; в steady-state дельты единичные.',
                'effect_text'        => 'Ограничивает скорость перестройки и фоновой поддержки популяции. Влияет на нагрузку БД (RAND-выборки клеток) и скорость сходимости к градиенту.',
                'above_effect_text'  => 'Выше: быстрее сходимость, тяжелее тик (больше RAND-запросов по карте 1M клеток). >1000 = ощутимая нагрузка каждую минуту.',
                'below_effect_text'  => 'Ниже: мягче нагрузка, дольше перестройка (50/мин ≈ 4 часа); 0 = крон фактически заморожен (no-op при включённом killswitch).',
                'recommended_min'    => 50,
                'recommended_max'    => 500,
                'hard_min'           => 0,
                'hard_max'           => 2000,
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
            'world.raiders.gradient_enabled',
            'world.raiders.population_l15',
            'world.raiders.population_l20',
            'world.raiders.population_l25',
            'world.raiders.l20_y_max',
            'world.raiders.l25_y_max',
            'world.raiders.y_cutoff',
            'world.raiders.max_adjust_per_tick',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
