<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W14b (vNext2, Фаза 3, ADR-068) — GameSettings торга/bargain (admin-tunable, ADR-024).
 *
 * Детерминированная скидка от `trading_karma` (RNG-fence safe). Формула:
 *   bargain_pct = min(max_pct, intdiv(trading_karma, divisor)).
 * Default divisor=10, max_pct=15 → karma 100 = 10%, karma 150+ = 15% cap.
 *
 * `caravan.bargain.enabled` (bool, default **false = DORMANT**): кнопка «💱 Торговаться»
 * не показывается, торг не применяется. Активация в конце ROADMAP. Idempotent.
 */
class W14bSeedCaravanBargainGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'caravan.bargain.enabled',
                'category'           => 'world',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch торга у караванов (ADR-068 W14b). true — кнопка «💱 Торговаться» сбивает цену детерминированно по характеристике Карма торговли (trading_karma). false — DORMANT: торга нет, цена как есть (V25/W5/W14a). Активация в конце ROADMAP с консолидированным анонсом.',
                'effect_text'        => 'CaravanLookAction показывает «💱 Торговаться» на resource/bundle-offer\'ах; bargained-режим рендерит сниженную цену; CaravanBuyAction (caravanBuyBargain_) применяет скидку (пересчитывает от trading_karma). Стэкается поверх caravan fix-discount, ограничено max_pct.',
                'above_effect_text'  => 'n/a (bool).',
                'below_effect_text'  => 'false (текущий default) = торг выключен.',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.bargain.max_pct',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 15,
                'default_value_text' => '15',
                'rationale_text'     => 'Максимальная скидка торга (%) поверх caravan fix-discount. 15% — заметная награда за высокую Карму торговли, но не делает караваны почти-бесплатными (−30% рынок × −15% торг = ~0.595× рынка).',
                'effect_text'        => 'Cap для bargain_pct = min(max_pct, intdiv(trading_karma, divisor)).',
                'above_effect_text'  => 'При 40%+ караваны становятся слишком дешёвым источником редких ресурсов → инфляция, теряется ценность gold.',
                'below_effect_text'  => 'При 0 торг ничего не даёт (мёртвая кнопка). 5-20% — осмысленный диапазон.',
                'recommended_min'    => '5',
                'recommended_max'    => '25',
                'hard_min'           => '0',
                'hard_max'           => '90',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.bargain.divisor',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 10,
                'default_value_text' => '10',
                'rationale_text'     => 'Делитель Кармы торговли для скидки торга: bargain_pct = intdiv(trading_karma, divisor). При 10 и стартовой karma 100 → 10% (до cap max_pct). Меньше делитель → щедрее торг.',
                'effect_text'        => 'CaravanService::bargainDiscountPct. trading_karma default 100 (GameBalance). karma растёт/падает от сделок (BuyCraftConfirmAction).',
                'above_effect_text'  => 'При 25 даже karma 100 даёт лишь 4% → торг почти незаметен, Карма торговли как стат обесценивается.',
                'below_effect_text'  => 'При 2 karma 100 = 50% (но cap max_pct срежет) → почти все упираются в cap, дифференциация по карме теряется.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '1000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
            if (! empty($exists)) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['caravan.bargain.enabled', 'caravan.bargain.max_pct', 'caravan.bargain.divisor'])
            ->delete();
    }
}
