<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V16 (ROADMAP-vNext Фаза 4, ADR-047) — GameSettings специализации (ADR-024).
 *
 * Workshop specialization (оружейник/медик/инженер): матч-ветка крафтит быстрее.
 *  - specialization.enabled               — killswitch всего слоя.
 *  - specialization.min_level             — уровень для выбора ветки.
 *  - specialization.craft_time_multiplier — время крафта матч-ветки (<1.0 = быстрее).
 *  - specialization.respec_cost_gold      — стоимость смены ветки (gold-sink).
 *  - specialization.respec_cooldown_days  — кулдаун смены ветки.
 *
 * category=craft (домен производства). Idempotent (skip if exists).
 */
class V16SeedSpecializationGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'specialization.enabled',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch системы крафт-специализаций. true — персонаж выбирает ветку (оружейник/медик/инженер) и крафт «своей» категории идёт быстрее. false — выбор недоступен, бонус не применяется (как до V16).',
                'effect_text'        => 'SpecializationService::isEnabled. false → меню выбора скрыто/заблокировано, getCraftTimeMultiplierFor возвращает 1.0 (no-op).',
                'above_effect_text'  => 'true: ось крафт-специализации активна (выбор ветки даёт −% времени своей категории).',
                'below_effect_text'  => 'false: мгновенный откат слоя без редеплоя (выбранные ветки сохраняются, но бонус не действует).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'specialization.min_level',
                'value_type'         => 'int',
                'value_int'          => 5,
                'default_value_text' => '5',
                'rationale_text'     => 'Минимальный уровень персонажа для выбора специализации (5). Не грузим новичков выбором ветки в первые минуты — дать освоить базовый крафт.',
                'effect_text'        => 'SpecializationAction блокирует выбор, если characters.level < этого значения (показывает «доступно с уровня N»).',
                'above_effect_text'  => 'Выше (напр. 15) — специализация откладывается надолго, ось прогрессии включается поздно.',
                'below_effect_text'  => 'Ниже (1) — новичок выбирает ветку сразу, не понимая категорий крафта; риск случайного выбора.',
                'recommended_min'    => '1',
                'recommended_max'    => '20',
                'hard_min'           => '1',
                'hard_max'           => '50',
            ],
            [
                'setting_key'        => 'specialization.craft_time_multiplier',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => 'Множитель времени крафта для категории «своей» ветки (−10%). Специалист быстрее работает в своём ремесле. Стакается мультипликативно с Workshop/Lab и «Сытостью».',
                'effect_text'        => 'GenericCraftActionStart::calculateCraftingDuration × этот множитель, если ветка персонажа совпадает с zone_name рецепта. Детерминир., min 1 минута.',
                'above_effect_text'  => 'При 0.99 — ускорение незаметно (−1%), выбирать ветку нет смысла.',
                'below_effect_text'  => 'При 0.60 — специализация даёт −40% к крафту своей категории, обесценивает Workshop/Lab и делает off-branch крафт мучительным по сравнению.',
                'recommended_min'    => '0.80',
                'recommended_max'    => '0.97',
                'hard_min'           => '0.50',
                'hard_max'           => '1.00',
            ],
            [
                'setting_key'        => 'specialization.respec_cost_gold',
                'value_type'         => 'int',
                'value_int'          => 5000,
                'default_value_text' => '5000',
                'rationale_text'     => 'Стоимость смены специализации в золоте (первый выбор бесплатный). Делает ветку значимым выбором + gold-sink, но не «тюрьмой» (можно передумать). Не P2W — золото из игровой экономики.',
                'effect_text'        => 'SpecializationChooseAction при смене (не первом выборе) списывает это золото; недостаточно → смена запрещена.',
                'above_effect_text'  => 'Выше (напр. 50000) — смена почти недостижима, ветка де-факто перманентна.',
                'below_effect_text'  => 'Ниже/0 — смена почти даром, ветка обесценивается (swap под каждый крафт).',
                'recommended_min'    => '1000',
                'recommended_max'    => '20000',
                'hard_min'           => '0',
                'hard_max'           => '1000000',
            ],
            [
                'setting_key'        => 'specialization.respec_cooldown_days',
                'value_type'         => 'int',
                'value_int'          => 7,
                'default_value_text' => '7',
                'rationale_text'     => 'Кулдаун между сменами специализации (дней). Вместе со стоимостью не даёт свопать ветку под каждый крафт; ветка — осознанный выбор на период.',
                'effect_text'        => 'SpecializationChooseAction запрещает смену, если now < specialization_changed_at + N дней (показывает, когда можно).',
                'above_effect_text'  => 'Выше (напр. 30) — смена редка, ошибка выбора болезненна для новичка.',
                'below_effect_text'  => 'Ниже/0 — кулдаун не сдерживает, остаётся только цена; при дешёвой смене ветка обесценивается.',
                'recommended_min'    => '1',
                'recommended_max'    => '30',
                'hard_min'           => '0',
                'hard_max'           => '365',
            ],
        ];

        $defaults = [
            'category'        => 'craft',
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
            'specialization.enabled',
            'specialization.min_level',
            'specialization.craft_time_multiplier',
            'specialization.respec_cost_gold',
            'specialization.respec_cooldown_days',
        ])->delete();
    }
}
