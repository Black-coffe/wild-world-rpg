<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V24 (ADR-056) — GameSettings для селективной страховки крафта (NPC-страховой
 * агент на базе). Pre-paid вечный полис на конкретные строки crafted_items_log.
 *
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V24SeedCraftInsuranceGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'craft_insurance.enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch селективной страховки крафта (V24). true — игрок может страховать дорогие предметы (robots/workbench/transport) за gold; LootProcessor пропускает insured-строки при смерти. false — кнопка скрыта, insured=1 строки игнорируются (восстанавливают обычное поведение death-loss).',
                'effect_text'        => 'CraftInsuranceService::enabled. false → меню «🛡 Страховка крафта» скрыто, CraftInsureItemAction отклоняет; LootProcessor читает insured но при killswitch=off игнорирует.',
                'above_effect_text'  => 'true: эндгейм-игроки защищают дорогие robots/transport, спокойнее ходят в опасные зоны → больше PvE-активности.',
                'below_effect_text'  => 'false: моментальный откат — death-loss работает по-старому (3% от ВСЕХ crafted_items, включая insured).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft_insurance.policy_fraction',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.2,
                'default_value_text' => '0.2',
                'rationale_text'     => 'Доля от воссоздательной стоимости предмета (gold + материалы × market price), которую игрок платит за ВЕЧНЫЙ полис. 0.2 = 20% от стоимости перекрафта. Tier-3 робот стоимостью ~40 000 gold + 5 000 материалов → страховка ~9 000g (за защиту от потери при death). Окупается уже на 1 смерти (теряется 3% × 45 000 = 1 350g, но потеря целого робота при PvP loot transfer = весь предмет).',
                'effect_text'        => 'CraftInsuranceService::policyFraction. cost = ceil((gold_required + Σ qty×market_price) × policy_fraction × log_quantity) + min_cost_gold floor.',
                'above_effect_text'  => 'При 0.5 страховка = 50% перекрафта → не оправдывает (выгоднее перекрафтить если потеряешь). Sink активный, но adoption низкий.',
                'below_effect_text'  => 'При 0.05 страховка почти бесплатная → все страхуют всё → death-механика теряет смысл; gold-sink схлопывается.',
                'recommended_min'    => '0.10',
                'recommended_max'    => '0.40',
                'hard_min'           => '0.01',
                'hard_max'           => '1.50',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft_insurance.eligible_types',
                'category'           => 'resources',
                'value_type'         => 'string',
                'value_string'       => 'robots,workbench,transport',
                'default_value_text' => 'robots,workbench,transport',
                'rationale_text'     => 'CSV-список crafted_items.type которые можно застраховать. По прод-данным V24-аудита: robots 1933 строк, workbench 992, transport 1011 — дорогие активы тяжело перекрафтить. Tools/components/food исключены: дешёвый перекрафт, мелкий риск (3% от 5813 tools = ~174 на сервер, окупается за день добычи).',
                'effect_text'        => 'CraftInsuranceService::eligibleTypes(): array<string>. Используется в CraftInsuranceListAction (фильтр SELECT) и CraftInsureItemAction (валидация перед insure).',
                'above_effect_text'  => 'Расширение до "robots,workbench,transport,tool,weapon": ~5800 дополнительных tools страхуются → UX-шум в списке + распыляет sink.',
                'below_effect_text'  => 'Сужение до "robots" только: workbench/transport теряются на смерти → недовольство владельцев. Меньше gold-sink-узкий target.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'craft_insurance.min_cost_gold',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 50,
                'default_value_text' => '50',
                'rationale_text'     => 'Минимальная gold-цена полиса (защита от дешёвых предметов с нулевой market price). 50g — символическая плата, не обременяет, исключает «бесплатный полис» эксплойт.',
                'effect_text'        => 'CraftInsuranceService::computePolicyCost: после расчёта применяется max(min_cost_gold, computed).',
                'above_effect_text'  => 'При 500g даже самые мелкие eligible предметы стоят 500g → новые игроки на L5-10 не могут позволить.',
                'below_effect_text'  => 'При 0 полис на самые дешёвые предметы (например, базовый workbench) ≈0 → эксплойт mass-страховать ради 0-gold-loss.',
                'recommended_min'    => '20',
                'recommended_max'    => '200',
                'hard_min'           => '0',
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
                'craft_insurance.enabled',
                'craft_insurance.policy_fraction',
                'craft_insurance.eligible_types',
                'craft_insurance.min_cost_gold',
            ])
            ->delete();
    }
}
