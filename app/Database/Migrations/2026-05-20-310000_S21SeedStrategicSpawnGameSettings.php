<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * S21 (v0.51.203) — seed spawn-cap GameSettings для 3 strategic-объектов
 * (constitutional admin-tunable balance, ADR-024; ROADMAP-CRAFT §8 Фаза 5).
 *
 * **Почему GameSettings, а не world_objects.max_count.** Spawn-count = balance-
 * параметр («📊 лимиты/пороги» из CLAUDE.md §🎛️) — обязан быть admin-tunable с
 * rich rationale. Исторический max_count 50/30/80 — это «общий лут» (как
 * warehouse ×5000), что противоречит «strategic / rare». WorldObjectGeneratorHandler
 * для strategic читает cap отсюда (`world.strategic.<name_lc>.max_spawns`),
 * fallback — колонка max_count (выставлена в зеркало этих значений соседней
 * миграцией для honest admin-display).
 *
 * **Баланс.** Strategic-объект single_use (после лута → cleared → респавн до cap).
 * Discovery даёт +500 фракции (EndgameScoring) + авто-завершение StrategicCapture
 * квеста (gold). Малый cap = редкая ценная находка; большой cap = farm +500,
 * инфляция эндгейм-гонки. Baseline rare-но-findable на карте 1000×1000 / 358 чаров
 * с туманом войны.
 *
 * Категория `world` (Мир и события). value_type=int. Idempotent.
 */
class S21SeedStrategicSpawnGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.strategic.bunker.max_spawns',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Сколько Бункеров (Военные → +500) одновременно активно на карте. 5 — редкая ценная цель: биомы Поля/Вулканы/Пустыни (~137k клеток), туман войны делает находку событием, но гонка фракций реально движется.',
                'effect_text'        => 'WorldObjectGeneratorHandler докидывает Bunker до этого числа active-записей в biome_world_object_map. Каждый discovery: +500 Военным + авто-завершение StrategicCaptureBunker (+5000 gold, +100 эндгейм-очков) + богатый лут (Sulfur/RareMetals/Oil/Ironstone + оружие/медицина).',
                'above_effect_text'  => 'При 30+ Бункеры повсюду → +500 фармятся, Военные доминируют в эндгейм-гонке, теряется «strategic» статус (становится обычным лут-объектом как warehouse).',
                'below_effect_text'  => 'При 1 на 1000×1000 с туманом войны Бункер почти невозможно найти → L15-квест StrategicCaptureBunker мёртв, канон-обещание снова не выполнено.',
            ],
            [
                'setting_key'        => 'world.strategic.technopark.max_spawns',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Сколько Технопарков (Инженеры → +500) одновременно на карте. 4 — реже Бункера: высокотех-объект эндгейма, гейт DiamondPickaxe (T3-инструмент S20). Биомы Леса/Пещеры (~260k клеток, но Пещеры всего ~1.1k).',
                'effect_text'        => 'Генератор докидывает Technopark до этого cap. Discovery: +500 Инженерам + авто-завершение StrategicCaptureTechnopark (+8000 gold) + лут (RareMetals/Wiring/Crystals + электроника/WorkbenchOne). Требует DiamondPickaxe — связь с T3-крафтом.',
                'above_effect_text'  => 'При 20+ Инженеры легко набирают +500, перекос гонки в их сторону, обесценивается DiamondPickaxe-гейт.',
                'below_effect_text'  => 'При 1 L20-квест StrategicCaptureTechnopark практически непроходим (мало клеток в Пещерах + туман войны).',
            ],
            [
                'setting_key'        => 'world.strategic.ghostcity.max_spawns',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Сколько Городов-призраков (Партизаны → +500) на карте. 8 — самый «частый» strategic: низший порог входа (L12-квест, гейт только IronPickaxe), тематика заброшенного города. Биомы Горы/Поля/Пустыни (~296k клеток).',
                'effect_text'        => 'Генератор докидывает GhostCity до cap. Discovery: +500 Партизанам + авто-завершение StrategicCaptureGhostCity (+4000 gold) + лут (Wood/Pebble/Stones/Crops + инструменты/бинты). Доступный entry-strategic для ранних фракционеров.',
                'above_effect_text'  => 'При 40+ Города-призраки повсюду → Партизаны фармят +500, гонка несбалансирована, теряется ценность находки.',
                'below_effect_text'  => 'При 1 даже доступный L12-квест почти невыполним, фракция Партизан лишена своего strategic-источника очков.',
            ],
        ];

        $shared = [
            'category'        => 'world',
            'value_type'      => 'int',
            'recommended_min' => '1',
            'recommended_max' => '20',
            'hard_min'        => '0',
            'hard_max'        => '200',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('game_settings')
                ->where('setting_key', $row['setting_key'])
                ->get()
                ->getRowArray();
            if (! empty($existing)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge($shared, $row));
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'world.strategic.bunker.max_spawns',
                'world.strategic.technopark.max_spawns',
                'world.strategic.ghostcity.max_spawns',
            ])
            ->delete();
    }
}
