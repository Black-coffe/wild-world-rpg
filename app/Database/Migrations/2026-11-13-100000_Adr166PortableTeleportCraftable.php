<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * «Портативный телепорт» становится достижимым: настройки баланса + починка шаблона предмета.
 *
 * Предыстория. Предмет `PortableTeleport` лежит в `crafted_items` с 2025 года, экран
 * «📡 Телепорт» умеет его применять, а взять его было НЕГДЕ — рецепта не существовало ни
 * в одном крафт-разделе. На проде на 2026-08-06: 0 владельцев, 0 применений. Рецепт добавлен
 * ({@see \App\Services\Player\Craft\PortableTeleportRecipe}), эта миграция закрывает данные.
 *
 * 1) GameSettings `craft.portable_teleport.*` (category=craft, ADR-024): killswitch,
 *    цена в золоте, длительность сборки, заряды, минимальный уровень.
 *
 * 2) Шаблон предмета приводится в порядок (UPDATE по `name_eng`, идемпотентно):
 *    - 🔴 `price` 120600 → 18000. Цена продажи торговцу считается от `price` и достигает
 *      `price × 1.0` при максимальной карме ({@see \App\Services\Economy\TradePricingService}).
 *      При прежнем прайсе круг «собрал за 30 000 → продал за ~120 000» печатал бы золото —
 *      ровно класс бага ADR-157. Теперь продажа заведомо убыточнее сборки.
 *    - `durability_count` 34 → 15: столько же, сколько выдаёт рецепт (одно число в двух
 *      местах — источник рассинхрона; шаблон подтягиваем к настройке).
 *    - `type` transport → teleport: предмет встаёт в одну категорию с маяком и рюкзаком
 *      (иначе в лавке он одиноко висел бы в «🛴 Транспорт»).
 *    - `crafting_time` 10 → 60 и человеческое `description` (было NULL).
 *    Ни один игрок предметом не владеет (0 строк в `crafted_items_log`) → правка шаблона
 *    ничей прогресс не задевает.
 *
 * Таблиц и player-колонок не создаёт → `Config\WipeManifest` не трогаем
 * (`game_settings` и `crafted_items` уже классифицированы как KEEP).
 */
class Adr166PortableTeleportCraftable extends Migration
{
    private const ITEM = 'PortableTeleport';

    /** @var list<string> */
    private const KEYS = [
        'craft.portable_teleport.enabled',
        'craft.portable_teleport.gold_cost',
        'craft.portable_teleport.duration_minutes',
        'craft.portable_teleport.charges',
        'craft.portable_teleport.min_level',
    ];

    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->row('craft.portable_teleport.enabled', 'bool', ['value_bool' => 1], 'true', null, null, null, null,
            'Killswitch сборки портативного телепорта. DEFAULT true: механика применения существовала и работала годами, недоставало только входа — держать её выключенной значило бы снова оставить предмет мёртвым.',
            'PortableTeleportRecipe::enabled(). OFF → экран рецепта остаётся достижимым, но объясняет, что сборка закрыта, и не запускает задачу.',
            'Значений выше нет (bool). ON — сборка доступна всем, кто выполнил пререквизиты.',
            'false (OFF): сборка закрыта, уже собранные устройства продолжают работать (гейт только на запуск).');

        $rows[] = $this->row('craft.portable_teleport.gold_cost', 'int', ['value_int' => 30000], '30000', '15000', '60000', '0', '10000000',
            'Цена сборки в золоте. 30 000 — заметно дороже рюкзака-телепорта (21 000), потому что портативный снимает главное ограничение рюкзака: часовое ожидание. 🔴 Обязан оставаться ВЫШЕ прайса предмета (18 000), иначе круг «собрал → продал торговцу» печатает золото (класс бага ADR-157).',
            'PortableTeleportRecipe::goldCost(). Списывается при запуске сборки, вместе с материалами.',
            'Выше (60 000+): устройство становится роскошью поздней игры, собирают единицы — риск снова получить мёртвый предмет.',
            'Ниже (18 000 и меньше): продажа торговцу перестаёт быть убыточной → золотой насос; плюс мгновенные возвраты обесценивают расстояния.');

        $rows[] = $this->row('craft.portable_teleport.duration_minutes', 'int', ['value_int' => 60], '60', '30', '180', '1', '10080',
            'Длительность сборки в минутах. 60 — дольше маяка (30) и рюкзака (45): устройство сильнее обоих по удобству, и время сборки это отражает.',
            'PortableTeleportRecipe::durationMinutes(). Задаёт end_time задачи craftPortableTeleport; сборка идёт в фоне.',
            'Выше (180+): сборка превращается в «поставил и забыл на вечер» — слабее ощущается как награда за материалы.',
            'Ниже (30): собирается быстрее маяка при большей пользе — ломает лестницу телепорт-рецептов.');

        $rows[] = $this->row('craft.portable_teleport.charges', 'int', ['value_int' => 15], '15', '5', '40', '1', '1000',
            'Зарядов у собранного устройства. 15 мгновенных возвратов — ощутимый запас «на чёрный день», но не замена пешему пути: рюкзак даёт больше применений, зато раз в час.',
            'PortableTeleportRecipe::charges(). Пишется в crafted_items_log.durability_count при выдаче и при обновлении стака (TeleportItemConsumer).',
            'Выше (40+): возврат домой перестаёт быть ресурсом, карта «сжимается» — падает ценность маяков и похода.',
            'Ниже (5): устройство почти одноразовое при дорогой сборке — собирать невыгодно, предмет рискует снова стать мёртвым.');

        $rows[] = $this->row('craft.portable_teleport.min_level', 'int', ['value_int' => 15], '15', '10', '30', '1', '200',
            'Минимальный уровень персонажа для сборки. 15 — выше входа в раздел телепортов: к этому моменту у игрока уже есть база, Центр телепортации и понимание, зачем нужен быстрый возврат.',
            'PortableTeleportRecipe::minLevel(). Проверяется и на экране рецепта (объясняет замок), и повторно при запуске сборки.',
            'Выше (30+): устройство уезжает в поздний контент, ранние игроки о нём даже не узнают.',
            'Ниже (10): мгновенные возвраты появляются раньше, чем игрок научился ходить по карте — обесценивает ранний риск пути домой.');

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert(array_merge([
                'value_int' => null, 'value_float' => null, 'value_bool' => null, 'value_string' => null,
                'recommended_min' => null, 'recommended_max' => null, 'hard_min' => null, 'hard_max' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $row));
        }

        // Шаблон предмета. Идемпотентно: повторный прогон выставит те же значения.
        $item = $this->db->table('crafted_items')->where('name_eng', self::ITEM)->get()->getRowArray();
        if (! empty($item)) {
            $this->db->table('crafted_items')->where('name_eng', self::ITEM)->update([
                'price'            => 18000,
                'durability_count' => 15,
                'type'             => 'teleport',
                'direction_craft'  => 'teleporter',
                'crafting_time'    => 60,
                'crafting_location' => 'anywhere',
                'effect'           => 'Мгновенный возврат на базу без ожидания между применениями',
                'description'      => 'Карманное устройство привязки к базе: переносит домой из любой точки острова, тратя один заряд',
                'updated_at'       => $now,
            ]);
        }
    }

    /**
     * @param array<string,int|string> $value
     * @return array<string,mixed>
     */
    private function row(string $key, string $type, array $value, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
        return array_merge([
            'setting_key'        => $key,
            'category'           => 'craft',
            'value_type'         => $type,
            'default_value_text' => $defaultText,
            'rationale_text'     => $rationale,
            'effect_text'        => $effect,
            'above_effect_text'  => $above,
            'below_effect_text'  => $below,
            'recommended_min'    => $recMin,
            'recommended_max'    => $recMax,
            'hard_min'           => $hardMin,
            'hard_max'           => $hardMax,
        ], $value);
    }

    public function down(): void
    {
        $this->db->table('game_settings')->whereIn('setting_key', self::KEYS)->delete();
        // Шаблон предмета обратно НЕ откатываем: прежние значения (price 120600 при
        // durability 34) были заготовкой недостижимого предмета и давали золотой насос.
    }
}
