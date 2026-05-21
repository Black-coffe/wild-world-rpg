<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V6 (ADR-033) — 13 farming.* GameSettings (constitutional admin-tunable, ADR-024).
 *
 * Активная посадка — слой поверх пассивной теплицы. Все рычаги (killswitch,
 * rotation, slot-кап, drop-шанс, grow-времена, yields) live-tunable без редеплоя.
 * category='resources' (параметры управляют производством ресурсов). Каждый ключ
 * с rich rationale (why/effect/above/below) — invariant ADR-024. Idempotent.
 */
class V6SeedFarmingGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // [key, type, int, float, bool, default_text, rationale, effect, above, below, rec_min, rec_max, hard_min, hard_max]
        $rows = [
            [
                'setting_key'        => 'farming.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch активного слоя земледелия (посадка семян). true — игроки могут крафтить семена и сажать культуры в теплице. Пассивная продукция теплицы работает независимо от этого флага.',
                'effect_text'        => 'false → меню «🌱 Посадить» и старт посадки недоступны (FarmingService::isEnabled), сид-крафт скрыт. Пассивная теплица (GreenhouseProductionHandler) продолжает работать.',
                'above_effect_text'  => 'true (вкл): активный слой доступен — новая ось вовлечения поверх пассива.',
                'below_effect_text'  => 'false (выкл): мгновенный откат активного слоя без редеплоя (пассив не тронут, 0 регрессии).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'farming.rotation.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch бонуса севооборота. true — посадка культуры, отличной от прошлой (characters.last_planted_crop), даёт бонус к урожаю. Поощряет чередование культур (P5 optimizer-глубина).',
                'effect_text'        => 'false → бонус севооборота не применяется (всегда baseline yield), даже если культура сменилась.',
                'above_effect_text'  => 'true (вкл): чередование культур вознаграждается (+farming.rotation.bonus_percent).',
                'below_effect_text'  => 'false (выкл): все посадки дают одинаковый baseline-урожай, стимула чередовать нет.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'farming.rotation.bonus_percent',
                'value_type'         => 'int',
                'value_int'          => 20,
                'default_value_text' => '20',
                'rationale_text'     => 'На сколько % выше урожай, если посаженная культура отличается от прошлой (севооборот). 20% — заметная награда за планирование, не ломающая баланс.',
                'effect_text'        => 'PlantCropCompletionHandler множит yield на (1 + 0.20), если при старте crop != last_planted_crop и rotation включён. Детерминированно (без RNG).',
                'above_effect_text'  => 'При 100%+ севооборот удваивает урожай → доминирующая стратегия, монокультура бессмысленна.',
                'below_effect_text'  => 'При 0 севооборот не даёт прибавки — фича-имя «crop rotation» теряет смысл.',
                'recommended_min'    => '5',
                'recommended_max'    => '50',
                'hard_min'           => '0',
                'hard_max'           => '300',
            ],
            [
                'setting_key'        => 'farming.max_concurrent_plantings',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'Сколько посадок (растущих культур) игрок может вести одновременно. 2 — позволяет параллелить, но не превращает теплицу в ферму-конвейер; анти-flood барьер.',
                'effect_text'        => 'PlantCropActionStart отклоняет старт, если активных posadok-тасков (status=in_work, task=plantCrop) уже >= этого числа.',
                'above_effect_text'  => 'При 10+ игрок засаживает всё разом → пассив теряет смысл, экономия еды зашкаливает.',
                'below_effect_text'  => 'При 1 можно вести лишь одну посадку — слой ощущается тесным, мало вовлекает.',
                'recommended_min'    => '1',
                'recommended_max'    => '5',
                'hard_min'           => '1',
                'hard_max'           => '20',
            ],
            [
                'setting_key'        => 'farming.seed_drop_chance',
                'value_type'         => 'float',
                'value_float'        => 0.05,
                'default_value_text' => '0.05',
                'rationale_text'     => 'Шанс (0..1) найти случайное семя при завершении сбора ресурсов (Gather). 0.05 = ~5% сборов дают семя — мягкий вторичный источник для бутстрапа активного слоя (primary = крафт).',
                'effect_text'        => 'GatherTaskHandler после сохранения добычи бросает шанс; при успехе добавляет 1 случайное семя в character_resources и шлёт уведомление.',
                'above_effect_text'  => 'При 0.5+ семена сыплются почти каждый сбор → крафт семян обесценивается, инфляция урожая.',
                'below_effect_text'  => 'При 0 семена выпадают только крафтом — бутстрап чуть жёстче (но крафт доступен с базовых ресурсов).',
                'recommended_min'    => '0',
                'recommended_max'    => '0.25',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'farming.grow.berries_minutes',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Время роста ягод (мин) от посадки до сбора. 30 — быстрая культура (низкий риск, малый урожай).',
                'effect_text'        => 'PlantCropActionStart выставляет end_time = now + это число минут для культуры berries.',
                'above_effect_text'  => 'При 600+ ягоды растут слишком долго → культура нерентабельна против пассива.',
                'below_effect_text'  => 'При 1-5 урожай почти мгновенный → спам-фарм, обесценивание.',
                'recommended_min'    => '10',
                'recommended_max'    => '180',
                'hard_min'           => '1',
                'hard_max'           => '1440',
            ],
            [
                'setting_key'        => 'farming.grow.mushrooms_minutes',
                'value_type'         => 'int',
                'value_int'          => 45,
                'default_value_text' => '45',
                'rationale_text'     => 'Время роста грибов (мин). 45 — средне-быстрая культура.',
                'effect_text'        => 'PlantCropActionStart выставляет end_time = now + это число минут для культуры mushrooms.',
                'above_effect_text'  => 'При 600+ грибы растут слишком долго → нерентабельно.',
                'below_effect_text'  => 'При 1-5 урожай почти мгновенный → спам-фарм.',
                'recommended_min'    => '10',
                'recommended_max'    => '180',
                'hard_min'           => '1',
                'hard_max'           => '1440',
            ],
            [
                'setting_key'        => 'farming.grow.fruit_minutes',
                'value_type'         => 'int',
                'value_int'          => 40,
                'default_value_text' => '40',
                'rationale_text'     => 'Время роста фруктов (мин). 40 — средняя культура.',
                'effect_text'        => 'PlantCropActionStart выставляет end_time = now + это число минут для культуры fruit.',
                'above_effect_text'  => 'При 600+ фрукты растут слишком долго → нерентабельно.',
                'below_effect_text'  => 'При 1-5 урожай почти мгновенный → спам-фарм.',
                'recommended_min'    => '10',
                'recommended_max'    => '180',
                'hard_min'           => '1',
                'hard_max'           => '1440',
            ],
            [
                'setting_key'        => 'farming.grow.crops_minutes',
                'value_type'         => 'int',
                'value_int'          => 60,
                'default_value_text' => '60',
                'rationale_text'     => 'Время роста овощей/зерновых (мин). 60 — самая долгая культура (но и самый ценный по объёму sink).',
                'effect_text'        => 'PlantCropActionStart выставляет end_time = now + это число минут для культуры crops.',
                'above_effect_text'  => 'При 600+ овощи растут слишком долго → нерентабельно.',
                'below_effect_text'  => 'При 1-5 урожай почти мгновенный → спам-фарм.',
                'recommended_min'    => '15',
                'recommended_max'    => '240',
                'hard_min'           => '1',
                'hard_max'           => '1440',
            ],
            [
                'setting_key'        => 'farming.yield.berries',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Базовый урожай ягод (шт) за одну посадку (до бонуса севооборота). 8 — окупает 1 семя (~1 ягода вложена) с запасом, вознаграждая активный слой.',
                'effect_text'        => 'PlantCropCompletionHandler начисляет это число ресурса Berries (× rotation-mult) в character_resources.',
                'above_effect_text'  => 'При 100+ одна посадка заваливает едой → инфляция, пассив бессмыслен.',
                'below_effect_text'  => 'При 1-2 урожай не окупает семя+время → активный слой невыгоден, мёртв.',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '500',
            ],
            [
                'setting_key'        => 'farming.yield.mushrooms',
                'value_type'         => 'int',
                'value_int'          => 6,
                'default_value_text' => '6',
                'rationale_text'     => 'Базовый урожай грибов (шт) за посадку. 6 — окупает семя, чуть скромнее ягод (грибы реже нужны).',
                'effect_text'        => 'PlantCropCompletionHandler начисляет это число ресурса Mushrooms (× rotation-mult).',
                'above_effect_text'  => 'При 100+ инфляция грибов.',
                'below_effect_text'  => 'При 1-2 не окупает семя+время → мёртвый слой.',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '500',
            ],
            [
                'setting_key'        => 'farming.yield.fruit',
                'value_type'         => 'int',
                'value_int'          => 8,
                'default_value_text' => '8',
                'rationale_text'     => 'Базовый урожай фруктов (шт) за посадку. 8 — паритет с ягодами.',
                'effect_text'        => 'PlantCropCompletionHandler начисляет это число ресурса Fruit (× rotation-mult).',
                'above_effect_text'  => 'При 100+ инфляция фруктов.',
                'below_effect_text'  => 'При 1-2 не окупает семя+время → мёртвый слой.',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '500',
            ],
            [
                'setting_key'        => 'farming.yield.crops',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Базовый урожай овощей/зерновых (шт) за посадку. 5 — самый долгий рост, но зерно ценно для крафта.',
                'effect_text'        => 'PlantCropCompletionHandler начисляет это число ресурса Crops (× rotation-mult).',
                'above_effect_text'  => 'При 100+ инфляция зерна.',
                'below_effect_text'  => 'При 1-2 не окупает семя+время → мёртвый слой.',
                'recommended_min'    => '3',
                'recommended_max'    => '30',
                'hard_min'           => '1',
                'hard_max'           => '500',
            ],
        ];

        $defaults = [
            'category'        => 'resources',
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
            'farming.enabled',
            'farming.rotation.enabled',
            'farming.rotation.bonus_percent',
            'farming.max_concurrent_plantings',
            'farming.seed_drop_chance',
            'farming.grow.berries_minutes',
            'farming.grow.mushrooms_minutes',
            'farming.grow.fruit_minutes',
            'farming.grow.crops_minutes',
            'farming.yield.berries',
            'farming.yield.mushrooms',
            'farming.yield.fruit',
            'farming.yield.crops',
        ])->delete();
    }
}
