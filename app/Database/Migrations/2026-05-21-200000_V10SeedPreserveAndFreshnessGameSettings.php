<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V10 (vNext, Фаза 2 закрытие, ADR-035) — GameSettings для консервов + механики свежести.
 *
 *  - medical.<preserve>.heal_* (category=combat) — heal заготовок (UsePharmacyAction).
 *  - food.<preserve>.well_fed_minutes (category=craft) — сытость заготовок (дольше: премиум).
 *  - food.freshness.* (category=craft) — механика свежести (pure-bonus, ADR-035):
 *    блюда V8 «черствеют» через fresh_days дней → buff «Сытость» урезается до
 *    stale_satiety_percent% (heal НЕ трогается, еда НЕ теряется). Консервы — не портятся.
 *
 * Idempotent. Rich rationale (ADR-024).
 */
class V10SeedPreserveAndFreshnessGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [];

        // heal заготовок (category=combat).
        $preserveHeal = [
            ['stew_preserve', 'Тушёнка', 40, 40],
            ['dry_ration',    'Сухпаёк', 25, 35],
        ];
        foreach ($preserveHeal as [$snake, $ru, $hp, $td]) {
            $rows[] = $this->row("medical.{$snake}.heal_health", 'combat', 'int', $hp, null, null,
                "Сколько HP восстанавливает «{$ru}» (консерва). Шире — еда вытеснит медицину; ниже/0 — не лечит здоровье.",
                "UsePharmacyAction +{$hp} здоровья (cap 100).", '0', '100', '0', '100');
            $rows[] = $this->row("medical.{$snake}.heal_tired", 'combat', 'int', $td, null, null,
                "Сколько выносливости восстанавливает «{$ru}» (консерва). Шире — обесценит отдых; ниже/0 — не восстанавливает.",
                "UsePharmacyAction +{$td} выносливости (cap 100).", '0', '100', '0', '100');
        }

        // сытость заготовок (category=craft) — премиум: дольше блюд V8.
        $preserveSatiety = [
            ['stew_preserve', 'Тушёнка', 70],
            ['dry_ration',    'Сухпаёк', 60],
        ];
        foreach ($preserveSatiety as [$snake, $ru, $minutes]) {
            $rows[] = $this->row("food.{$snake}.well_fed_minutes", 'craft', 'int', $minutes, null, null,
                "Длительность «Сытости» от «{$ru}» ({$minutes} мин). Консервы дают дольше обычных блюд — премиум за shelf-stability.",
                "UsePharmacyAction ставит well_fed_until = now + {$minutes} мин. Консерва не черствеет (durability_time не ставится).",
                '10', '180', '0', '1440');
        }

        // механика свежести (category=craft).
        $rows[] = $this->row('food.freshness.enabled', 'craft', 'bool', null, null, 1,
            'Killswitch свежести. true — блюда V8 черствеют (buff «Сытость» урезается); false — все блюда всегда свежи (как до V10). НЕ влияет на heal/потерю еды.',
            'FoodBuffService::freshnessEnabled. false → GenericCraftCompletionHandler не ставит durability_time, eat не урезает сытость.',
            null, null, '0', '1');
        $rows[] = $this->row('food.freshness.fresh_days', 'craft', 'int', 2, null, null,
            'Сколько дней приготовленное блюдо остаётся свежим (полная сытость). 2 дня — съешь за пару дней или законсервируй. Слишком много — свежесть бессмысленна; 0 — мгновенно черствеет.',
            'GenericCraftCompletionHandler ставит crafted_items_log.durability_time = today + это число дней для perishable-блюд.',
            '1', '14', '1', '60');
        $rows[] = $this->row('food.freshness.stale_satiety_percent', 'craft', 'int', 50, null, null,
            'Какой % длительности «Сытости» даёт зачерствевшее блюдо (50% = вдвое короче buff). Еда НЕ теряется и лечит полностью — урезается только бонус. 100 — свежесть не важна; ниже — сильнее наказание за хранение.',
            'UsePharmacyAction: stale-блюдо → well_fed_minutes × этот %/100 (heal не трогается).',
            '20', '90', '0', '100');

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
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $key, string $cat, string $type, ?int $int, ?float $float, ?int $bool, string $rationale, string $effect, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax): array
    {
        $defaultText = $type === 'bool' ? ($bool === 1 ? 'true' : 'false') : (string) ($int ?? $float ?? '');
        return [
            'setting_key'        => $key,
            'category'           => $cat,
            'value_type'         => $type,
            'value_int'          => $int,
            'value_float'        => $float,
            'value_bool'         => $bool,
            'default_value_text' => $defaultText,
            'rationale_text'     => $rationale,
            'effect_text'        => $effect,
            'above_effect_text'  => 'Выше рекомендованного — см. rationale (баланс смещается).',
            'below_effect_text'  => 'Ниже рекомендованного — см. rationale (эффект теряет смысл).',
            'recommended_min'    => $recMin,
            'recommended_max'    => $recMax,
            'hard_min'           => $hardMin,
            'hard_max'           => $hardMax,
        ];
    }

    public function down(): void
    {
        $keys = [
            'medical.stew_preserve.heal_health', 'medical.stew_preserve.heal_tired',
            'medical.dry_ration.heal_health', 'medical.dry_ration.heal_tired',
            'food.stew_preserve.well_fed_minutes', 'food.dry_ration.well_fed_minutes',
            'food.freshness.enabled', 'food.freshness.fresh_days', 'food.freshness.stale_satiety_percent',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
