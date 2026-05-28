<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W14a (vNext2, Фаза 3, ADR-068) — GameSettings «богатого каравана» (admin-tunable, ADR-024).
 *
 * `caravan.bundle_chance` (float, default **0 = DORMANT**): шанс, что спавн станет
 * multi-resource bundle (2-4 ресурса) вместо одиночного offer'а. 0 → bundles не спавнятся
 * (текущее поведение V25). Активация в конце ROADMAP: флип >0.
 * `caravan.bundle_min` / `caravan.bundle_max` (int): кол-во товаров в богатом караване.
 * Idempotent.
 */
class W14aSeedCaravanBundleGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'caravan.bundle_chance',
                'category'           => 'world',
                'value_type'         => 'float',
                'value_float'        => 0.0,
                'default_value_text' => '0',
                'rationale_text'     => 'Шанс, что спавн каравана станет «богатым» (multi-resource bundle 2-4 ресурса) вместо одиночного offer\'а. Проверяется ПОСЛЕ drone-roll. Default 0 = DORMANT (bundles не спавнятся, караваны как в V25). Активация в конце ROADMAP (флип на 0.2-0.4) вместе с консолидированным анонсом.',
                'effect_text'        => 'SpawnCaravanCron: при срабатывании создаёт N=rand(bundle_min..bundle_max) caravan-строк с общим caravan_group_id+cell+expiry. CaravanLookAction рендерит их как один богатый караван со списком товаров.',
                'above_effect_text'  => 'При 0.7+ почти все караваны — богатые; одиночные редкие offer\'ы теряются, ценность «находки» размывается.',
                'below_effect_text'  => 'При 0 (текущий default) — dormant: только одиночные offer\'ы (V25/W5). После активации 0.25-0.35 — баланс между разнообразием и редкостью.',
                'recommended_min'    => '0',
                'recommended_max'    => '0.5',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.bundle_min',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'Минимум товаров в богатом караване (multi-resource bundle). 2 — минимально осмысленный «набор».',
                'effect_text'        => 'SpawnCaravanCron: нижняя граница rand для числа разных ресурсов в bundle.',
                'above_effect_text'  => 'При 4+ богатые караваны всегда крупные — много gold-sink за раз.',
                'below_effect_text'  => 'При 1 «bundle» вырождается в одиночный offer (нет смысла в группе).',
                'recommended_min'    => '2',
                'recommended_max'    => '4',
                'hard_min'           => '1',
                'hard_max'           => '10',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'caravan.bundle_max',
                'category'           => 'world',
                'value_type'         => 'int',
                'value_int'          => 4,
                'default_value_text' => '4',
                'rationale_text'     => 'Максимум товаров в богатом караване. 4 — комфортный список для Telegram-карточки (не перегружает кнопками).',
                'effect_text'        => 'SpawnCaravanCron: верхняя граница rand для числа разных ресурсов в bundle (clamp ≥ bundle_min). Ограничен пулом редких ресурсов (rarity≥7).',
                'above_effect_text'  => 'При 8+ карточка каравана = стена кнопок, неудобно в Telegram; риск исчерпать пул уникальных редких ресурсов.',
                'below_effect_text'  => 'При значении = bundle_min размер фиксирован (нет вариативности).',
                'recommended_min'    => '3',
                'recommended_max'    => '5',
                'hard_min'           => '1',
                'hard_max'           => '10',
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
            ->whereIn('setting_key', ['caravan.bundle_chance', 'caravan.bundle_min', 'caravan.bundle_max'])
            ->delete();
    }
}
