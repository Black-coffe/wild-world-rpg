<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ADR-094 — «Устаревание расходников» (медикаменты). GameSettings `craft.expiry.*`.
 *
 * Время-устаревание медикаментов (crafted_items.type='drug') с деградацией heal-эффекта
 * (предмет НЕ теряется). Зеркало свежести еды (V10/ADR-035), но для лечения. Lazy-eval.
 *
 *  - craft.expiry.enabled              — killswitch механики.
 *  - craft.expiry.stale_effect_percent — % heal-эффекта у просроченного медикамента.
 *  - craft.expiry.default_shelf_days   — fallback-срок для drug'ов без template durability_time.
 *
 * Idempotent. Rich rationale (ADR-024). Не создаёт таблиц / player-колонок → WipeManifest
 * не трогаем (game_settings уже KEEP).
 */
class Adr094SeedConsumableExpiryGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $rows[] = $this->row('craft.expiry.enabled', 'craft', 'bool', null, null, 1,
            'true', null, null, '0', '1',
            'Killswitch устаревания медикаментов. true — скрафченные drug-предметы получают срок годности (durability_time) и теряют часть лечения после просрочки; false — медикаменты бессрочны (как до ADR-094).',
            'ConsumableExpiryService::enabled. false → GenericCraftCompletionHandler не ставит durability_time для drug, UsePharmacyAction не урезает heal.',
            'true (вкл): новые медикаменты годны shelf-life дней, потом лечат слабее (деградация, не потеря).',
            'false (выкл): мгновенный откат фичи без редеплоя — все медикаменты снова бессрочны.');

        $rows[] = $this->row('craft.expiry.stale_effect_percent', 'craft', 'int', 50, null, null,
            '50', '30', '80', '0', '100',
            'Какой % heal-эффекта даёт ПРОСРОЧЕННЫЙ медикамент (50% = вдвое слабее). Предмет НЕ теряется и durability_count (заряды) не трогается — урезается только сила лечения. Зеркало food.freshness.stale_satiety_percent для еды.',
            'UsePharmacyAction: для drug с durability_time в прошлом heal-эффекты × этот %/100 (min |1|, чтобы что-то лечило).',
            'Выше (80-100): просрочка почти не штрафует → срок годности теряет смысл, нет стимула крафтить свежее.',
            'Ниже (0-30): просрочка почти обнуляет лечение → жёсткое наказание за хранение, давит казуалов (10-портретный риск).');

        $rows[] = $this->row('craft.expiry.default_shelf_days', 'craft', 'int', 30, null, null,
            '30', '7', '90', '1', '365',
            'Срок годности (дней) для медикаментов БЕЗ template-шелф-лайфа (crafted_items.durability_time NULL/0) — 33 из 43 drug-рецептов. 30 дней — заметный, но не давящий горизонт. Рецепты со своим durability_time используют его, не это.',
            'ConsumableExpiryService::defaultShelfDays → durability_time = today + это число дней при крафте drug без template-срока.',
            'Выше (90-365): медикаменты почти не устаревают → механика декоративна.',
            'Ниже (1-7): медикаменты портятся за дни → стоки бессмысленны, постоянная пере-крафтовка.');

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
    private function row(string $key, string $cat, string $type, ?int $int, ?float $float, ?int $bool, string $defaultText, ?string $recMin, ?string $recMax, ?string $hardMin, ?string $hardMax, string $rationale, string $effect, string $above, string $below): array
    {
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
            'above_effect_text'  => $above,
            'below_effect_text'  => $below,
            'recommended_min'    => $recMin,
            'recommended_max'    => $recMax,
            'hard_min'           => $hardMin,
            'hard_max'           => $hardMax,
        ];
    }

    public function down(): void
    {
        $keys = [
            'craft.expiry.enabled',
            'craft.expiry.stale_effect_percent',
            'craft.expiry.default_shelf_days',
        ];
        $this->db->table('game_settings')->whereIn('setting_key', $keys)->delete();
    }
}
