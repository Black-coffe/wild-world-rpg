<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-172 — «страховка защищает от риска, которого нет»: включаем розыгрыш
 * дробного остатка штрафа смерти на крафт-предметах.
 *
 * Audit (прод 2026-08-19): потеря крафта считалась как floor(quantity × penalty)
 * (LootProcessor::computeCraftLoss). Дорогие активы лежат в `crafted_items_log`
 * строками по одной штуке — дрон и робот покупаются/крафтятся с quantity=1.
 * floor(1 × 0.03) = 0, и даже floor(1 × 0.50) = 0: такой предмет не терялся при
 * смерти НИКОГДА. Чтобы строка вообще попала под списание, нужно было qty ≥ 34
 * (с базой) или qty ≥ 2 (без базы) — на проде строк с qty ≥ 34 ноль, при этом
 * 18 полисов крафт-страховки уже оплачены золотом. Игроку обещали «−3%
 * имущества» и продавали защиту от риска, который не наступал.
 *
 * Решение: целая часть списывается как раньше, дробный остаток разыгрывается
 * броском. qty=1 при −3% → 3% шанс потерять предмет целиком; матожидание потерь
 * ровно то, что игре и обещано. Страховка (insured=1) по-прежнему пропускает
 * строку целиком — теперь ей есть от чего защищать.
 *
 *  - combat.death.craft_fractional_loss — killswitch (1 = живо; 0 → байт-в-байт
 *    прежнее поведение, крафт при qty=1 неуязвим).
 *
 * Эконом-инвариант: НЕ faucet (ничего не выдаётся), sink усиливается ровно до
 * заявленного процента. Idempotent (паттерн Adr131). game_settings = KEEP,
 * новых таблиц нет — WipeManifest не трогаем.
 */
class Adr172CraftFractionalLossGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $row = [
            'setting_key'        => 'combat.death.craft_fractional_loss',
            'category'           => 'combat',
            'value_type'         => 'bool',
            'value_bool'         => 1,
            'default_value_text' => '1',
            'rationale_text'     => 'Разыгрывать ли дробный остаток штрафа смерти на крафт-предметах. Включено, потому что без этого floor() съедал весь штраф на строках с quantity=1 (дрон, робот, верстак, транспорт) — игра обещала «−3% имущества», а такие предметы не терялись вообще, и купленный полис крафт-страховки защищал от несуществующего риска (прод 2026-08-19: 18 оплаченных полисов, строк с qty >= 34 ноль).',
            'effect_text'        => 'LootProcessor::computeCraftLoss($items, $penalty, fractionalChance). Целая часть floor(qty × penalty) списывается как прежде; дробный остаток даёт шанс списать ещё одну штуку. qty=1 при penalty=0.03 → 3% шанс потерять предмет целиком. Строки insured=1 пропускаются до броска. Ресурсы и золото не затронуты.',
            'above_effect_text'  => 'Значений выше 1 нет — это тумблер. Включённое состояние и есть заявленный игре штраф.',
            'below_effect_text'  => 'При 0 механика гаснет: крафт-предметы с quantity=1 снова не теряются при смерти ни при каком штрафе, а крафт-страховка становится платой ни за что. Ставить 0 только как аварийный откат.',
            'recommended_min'    => null,
            'recommended_max'    => null,
            'hard_min'           => null,
            'hard_max'           => null,
            'value_int'          => null,
            'value_float'        => null,
            'value_string'       => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray();
        if (! empty($exists)) {
            return;
        }

        $this->db->table('game_settings')->insert($row);
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->where('setting_key', 'combat.death.craft_fractional_loss')
            ->delete();
    }
}
