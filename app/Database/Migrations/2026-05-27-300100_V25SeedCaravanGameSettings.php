<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V25 (ADR-057) — GameSettings для странствующих NPC-караванов.
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V25SeedCaravanGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'caravan.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch системы странствующих NPC-караванов (V25). true — cron спавнит караваны до max_active, игроки видят их на своих клетках и могут покупать редкие ресурсы со скидкой. false — cron no-op, существующие караваны не рисуются. Закрывает literal V25 «trade routes / NPC caravans».',
                'effect_text'        => 'CaravanService::enabled. false → SpawnCaravanCron no-op, CaravanAction отклоняет, кнопка «🚚 Каравaн» в move/gather-результатах скрыта.',
                'above_effect_text'  => 'true: эндгейм-игроки получают gold-sink для редких ресурсов, новички видят «мир оживает» (NPC появляются на карте).',
                'below_effect_text'  => 'false: моментальный откат слоя без редеплоя; существующие записи в таблице игнорируются.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.max_active',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Максимум одновременно активных караванов на карте. 5 — баланс между «редкость = ценность» (каждая встреча события) и доступностью (~1 на ярус). При 365 чарах и 5 караванах × 2 ч жизни шанс «встретить случайно» ~3-5% за сессию.',
                'effect_text'        => 'SpawnCaravanCron: если COUNT(status=active AND NOW()<expires_at) < max_active → spawn 1 (1 за тик крон-задачи).',
                'above_effect_text'  => 'При 20: караваны теряют редкость, видны почти везде; gold-sink усиливается, но flavor «находки» теряется.',
                'below_effect_text'  => 'При 1: редчайшие встречи (<1% шанс), gold-sink почти не работает; sklepнее имеет смысл выключить через killswitch.',
                'recommended_min'    => '2',
                'recommended_max'    => '15',
                'hard_min'           => '0',
                'hard_max'           => '50',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.lifetime_minutes',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 120,
                'default_value_text' => '120',
                'rationale_text'     => 'Время жизни одного каравана в минутах (от spawn до expires). 120 = 2 часа — достаточно для случайно встретившего игрока подойти и купить (учитывая move-таймеры), но не настолько долго чтобы скапливалось пуло «застывших» offers.',
                'effect_text'        => 'expires_at = spawned_at + lifetime_minutes. CaravanService::isActive фильтрует по NOW() < expires_at. SpawnCaravanCron пересчитывает active count с учётом expires_at.',
                'above_effect_text'  => 'При 480 (8 ч): караваны «застаиваются», offers устаревают; больше шанс встретить, но потеря момента «успей купить пока есть».',
                'below_effect_text'  => 'При 15 мин: почти невозможно успеть подойти (move занимает время); offers становятся «упущенными».',
                'recommended_min'    => '60',
                'recommended_max'    => '240',
                'hard_min'           => '5',
                'hard_max'           => '1440',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.discount_percent',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.3,
                'default_value_text' => '0.3',
                'rationale_text'     => 'Скидка от рыночной цены ресурса (resources.price) при покупке у каравана. 0.3 = -30% (цена = market × 0.7). Создаёт «выгоду подойти» — экономия за поиск и доход к каравану. Меньше — нет смысла идти, больше — экономика проседает (массовый фарм через caravans).',
                'effect_text'        => 'CaravanService::computePricePerUnit: price = max(1, ceil(market_price × (1 - discount_percent))). Используется при spawn (записывается в caravans.price_per_unit) и не пересчитывается потом — fix-price оффер.',
                'above_effect_text'  => 'При 0.7 (-70%): караваны = эксплойт-источник дешёвых ресурсов; рынок ResourcesBank проседает.',
                'below_effect_text'  => 'При 0.05 (-5%): нет смысла подходить, проще купить в shop.',
                'recommended_min'    => '0.15',
                'recommended_max'    => '0.45',
                'hard_min'           => '0.00',
                'hard_max'           => '0.90',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.qty_min',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимальное количество ресурса в offer одного каравана. Каждый спавн = случайное qty в [qty_min, qty_max].',
                'effect_text'        => 'SpawnCaravanCron: quantity = mt_rand(qty_min, qty_max).',
                'above_effect_text'  => 'При 500: караваны = mega-склад; одной покупки достаточно для нескольких крафтов.',
                'below_effect_text'  => 'При 10: смешно мало, караван бесполезен (не хватает на 1 крафт).',
                'recommended_min'    => '20',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.qty_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 200,
                'default_value_text' => '200',
                'rationale_text'     => 'Максимальное количество ресурса в offer одного каравана. См. qty_min.',
                'effect_text'        => 'См. qty_min.',
                'above_effect_text'  => 'См. qty_min above.',
                'below_effect_text'  => 'См. qty_min below.',
                'recommended_min'    => '50',
                'recommended_max'    => '500',
                'hard_min'           => '1',
                'hard_max'           => '5000',
                'created_at'         => $now,
                'updated_at'         => $now,
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
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', [
                'caravan.enabled',
                'caravan.max_active',
                'caravan.lifetime_minutes',
                'caravan.discount_percent',
                'caravan.qty_min',
                'caravan.qty_max',
            ])
            ->delete();
    }
}
