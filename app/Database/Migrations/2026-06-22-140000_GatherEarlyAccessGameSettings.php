<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * «Мягкий старт» добычи — сглаживание rarity-обрыва на низких уровнях (ADR-090).
 *
 * Проблема: getAllowedRarities(level) — бинарный гейт. На L1 доступна только rarity 10, а в
 * стартовых биомах (Леса/Реки) единственный rarity-10 ресурс — Вода → новичок собирает ТОЛЬКО
 * воду до L2. Бедный/непонятный онбординг («почему только вода?»).
 *
 * Решение (soft-ramp): редкие ресурсы доступны и раньше уровня разблокировки, но с малым выходом
 * factor = step^(tiersEarly) в окне window тиров. Т.к. базовое количество уже масштабируется
 * редкостью (rarity 10=60 ед., rarity 1=2), превью редких тиров near-zero, а близких к общему —
 * ощутимо → «вода доминирует (~80%) + базовое разнообразие (~20%)» на L1, экономика почти не
 * страдает. Killswitch OFF → жёсткий гейт как раньше (byte-identical).
 */
class GatherEarlyAccessGameSettings extends Migration
{
    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            [
                'setting_key'        => 'gather.early_access_enabled',
                'category'           => 'resources',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => '0',
                'rationale_text'     => 'ADR-090 «Мягкий старт»: на низких уровнях доступна только вода (единственный rarity-10 в стартовых биомах) → бедный онбординг. ON сглаживает rarity-обрыв: редкие тиры доступны раньше с малым выходом, растущим к уровню разблокировки. Default 0 = dormant (раскатываем после Tier-3).',
                'effect_text'        => 'GatherTaskHandler.calculateFoundResources + GatherTips: rarityYieldFactor(level,rarity) даёт множитель выхода вместо бинарного in_array-гейта. OFF → жёсткий гейт как раньше (byte-identical).',
                'above_effect_text'  => 'ON: L1 в Лесах/Реках даёт воду (полно) + песок/древесину/кору (по чуть-чуть) → разнообразие с первого уровня, туториал и реальный сбор консистентны.',
                'below_effect_text'  => '0 (default): L1 = только вода (rarity-10), остальное открывается строго по уровням (текущее поведение, 0 регрессии).',
                'recommended_min'    => '0',
                'recommended_max'    => '1',
                'hard_min'           => '0',
                'hard_max'           => '1',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'gather.early_access_window',
                'category'           => 'resources',
                'value_type'         => 'int',
                'value_int'          => 2,
                'default_value_text' => '2',
                'rationale_text'     => 'ADR-090: сколько rarity-тиров ВЫШЕ текущего уровня разблокировки доступны в режиме превью (с пониженным выходом). Default 2 = игрок видит трикл следующих 2 тиров. Окно ограничивает, насколько глубоко редкое «протекает» раньше времени.',
                'effect_text'        => 'rarityYieldFactor: при tiersEarly (unlock−level) в диапазоне 1..window → выход step^tiersEarly; глубже окна → 0. Действует только при early_access_enabled ON.',
                'above_effect_text'  => 'Выше (3-4): больше разных ресурсов в превью раньше → разнообразнее, но сильнее «протечка» редкого в раннюю экономику.',
                'below_effect_text'  => 'Ниже (1): превью только ближайшего тира (на L1 вода+песок), меньше разнообразия. 0 = превью выключено (эквивалент жёсткого гейта).',
                'recommended_min'    => '1',
                'recommended_max'    => '4',
                'hard_min'           => '0',
                'hard_max'           => '9',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'gather.early_access_step',
                'category'           => 'resources',
                'value_type'         => 'float',
                'value_float'        => 0.20,
                'default_value_text' => '0.20',
                'rationale_text'     => 'ADR-090: геометрический множитель выхода за КАЖДЫЙ тир раньше разблокировки. Выход = step^tiersEarly. Default 0.20 → 1 тир раньше = 20% базового, 2 тира = 4%. Подобран так, что вода остаётся доминантой (~75-80% добычи на L1), а базовое разнообразие ощутимо но не вытесняет воду.',
                'effect_text'        => 'rarityYieldFactor возвращает step^tiersEarly для тиров в окне превью. Меньше step → круче спад → вода доминирует сильнее.',
                'above_effect_text'  => 'Выше (0.35-0.5): превью-ресурсы выходят щедрее → доля воды падает (вода ~60%), быстрее насыщение редким на старте.',
                'below_effect_text'  => 'Ниже (0.10): превью почти символическое (вода ~90%), эффект близок к жёсткому гейту.',
                'recommended_min'    => '0.10',
                'recommended_max'    => '0.40',
                'hard_min'           => '0.01',
                'hard_max'           => '1.00',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];

        foreach ($rows as $row) {
            if (! empty($this->db->table('game_settings')->where('setting_key', $row['setting_key'])->get()->getRowArray())) {
                continue;
            }
            $this->db->table('game_settings')->insert($row);
        }
    }

    public function down(): void
    {
        $this->db->table('game_settings')
            ->whereIn('setting_key', ['gather.early_access_enabled', 'gather.early_access_window', 'gather.early_access_step'])
            ->delete();
    }
}
