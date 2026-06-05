<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-101 Фаза 0 — killswitch'и слоя поселений (admin-tunable, ADR-024). category=world.
 *
 * settlements.enabled (bool, default 0 dormant) — общий рубильник слоя: размещение жителей,
 * экран-хаб, safe-зоны, маркеры карты. settlements.zone_radius (int, default 0) — радиус
 * действия правил зоны вокруг якорной клетки (0 = только сама клетка). Idempotent.
 */
class SettlementsGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $keys = [
            [
                'setting_key'        => 'settlements.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch слоя статических NPC-поселений (ADR-101): аутпост/логово/оплоты/руины. true — жители размещаются на якорных клетках, работает экран-хаб города, safe-зоны и маркеры карты; false — слой dormant (0 player-эффекта). Ship dormant, активация пофазно после Tier-3.',
                'effect_text'        => 'SettlementPlacementHandler держит спавны жителей на settlements.anchor_cell; SettlementZoneService::policyAt гейтит safe/hostile-правила во встречах и авто-бою; экран-хаб и иконки карты. false → всё no-op.',
                'above_effect_text'  => 'true (вкл): на карте появляются постоянные поселения с услугами (торговля/ремонт/казино/квесты) и правилами зоны — новые точки притяжения, ретеншн, structure мира.',
                'below_effect_text'  => 'false (выкл, текущий default): мгновенный dormant без редеплоя; пустые таблицы settlements/settlement_npcs → крон-размещение no-op, 0 регрессии.',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'settlements.zone_radius',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'Радиус действия правил зоны поселения вокруг якорной клетки (метрика Чебышёва). 0 = правила только на самой якорной клетке. >0 = периметр (атмосфера «района города»). Старт с 0 (один якорь) — ADR-101 §8 рекомендация.',
                'effect_text'        => 'SettlementZoneService::policyAt(cell) считает клетку частью поселения, если она в radius вокруг anchor_cell. Влияет на safe/hostile-гейты и вход в экран-хаб.',
                'above_effect_text'  => 'выше (напр. 1-2): поселение занимает зону 3×3/5×5 — больше «город», но сложнее баланс (больше клеток под safe-правилами).',
                'below_effect_text'  => '0 (текущий): поселение = 1 клетка, минимальная площадь правил, проще баланс и smoke.',
                'hard_min'           => '0',
                'hard_max'           => '5',
            ],
        ];

        foreach ($keys as $row) {
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
        $this->db->table('game_settings')->whereIn('setting_key', ['settlements.enabled', 'settlements.zone_radius'])->delete();
    }
}
