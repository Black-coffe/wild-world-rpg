<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * V20 (ADR-051) — GameSettings для фракц-проекта (category=endgame).
 * Constitutional ADR-024: rationale/effect/above/below NOT NULL. Idempotent.
 */
class V20SeedFactionProjectGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'faction.project.enabled',
                'category'           => 'endgame',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch фракц-проекта (общий вклад → фракц-buff). true — члены фракции могут вносить золото в общий проект, при достижении порога вся фракция получает временный бафф крафта. false — экран/кнопка скрыты, бафф не применяется.',
                'effect_text'        => 'FactionProjectService::enabled. false → кнопка «Проект фракции» скрыта, deposit отклоняется, craftTimeMultiplierFor возвращает 1.0.',
                'above_effect_text'  => 'true: активна ось фракц-кооперации (вклад золота → общий бафф).',
                'below_effect_text'  => 'false: мгновенный откат слоя без редеплоя (накопленный progress сохраняется, но бафф не действует).',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'faction.project.threshold',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 20000,
                'default_value_text' => '20000',
                'rationale_text'     => 'Сколько золота-очков нужно накопить фракции, чтобы завершить цикл проекта и активировать бафф. 20000 = ~20 вкладов по 1000 (быстро для фракции 5-6, медленно для 1 члена). Баланс между «достижимо вместе» и «не бесплатно».',
                'effect_text'        => 'FactionProjectService::deposit: когда progress >= threshold → активирует buff_until, progress -= threshold (carry остатка), cycles_completed++.',
                'above_effect_text'  => 'При 100000 даже большая фракция копит долго → бафф редкий, кооперация буксует.',
                'below_effect_text'  => 'При 2000 порог берётся в пару вкладов → бафф почти всегда активен, теряет ценность как награда.',
                'recommended_min'    => '5000',
                'recommended_max'    => '100000',
                'hard_min'           => '100',
                'hard_max'           => '10000000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'faction.project.deposit_gold',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 1000,
                'default_value_text' => '1000',
                'rationale_text'     => 'Сколько золота списывается за один вклад в проект (фикс., один tap). 1000 — заметный, но посильный для эндгеймера взнос; progress растёт на эту же величину.',
                'effect_text'        => 'FactionProjectService::deposit списывает это золото у вкладчика и прибавляет к progress фракции.',
                'above_effect_text'  => 'При 10000 один вклад дорогой → мало вкладов, но порог берётся быстрее по числу tap-ов (если порог не подняли).',
                'below_effect_text'  => 'При 50 вклад символический → нужно очень много tap-ов, спам-кликанье ради прогресса.',
                'recommended_min'    => '200',
                'recommended_max'    => '5000',
                'hard_min'           => '1',
                'hard_max'           => '1000000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'faction.project.buff_duration_hours',
                'category'           => 'endgame',
                'value_type'         => 'int',
                'value_int'          => 24,
                'default_value_text' => '24',
                'rationale_text'     => 'На сколько часов активируется фракц-бафф крафта после завершения цикла проекта. 24 — сутки общей выгоды как награда за совместный вклад.',
                'effect_text'        => 'FactionProjectService::deposit при завершении ставит buff_until = now + это; craftTimeMultiplierFor активен, пока now < buff_until.',
                'above_effect_text'  => 'При 168 (неделя) бафф почти постоянный → теряет ощущение «события-награды».',
                'below_effect_text'  => 'При 1 бафф мелькает на час → не успевают воспользоваться, вклад не ощущается оправданным.',
                'recommended_min'    => '6',
                'recommended_max'    => '72',
                'hard_min'           => '1',
                'hard_max'           => '720',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'faction.project.craft_time_multiplier',
                'category'           => 'endgame',
                'value_type'         => 'float',
                'value_float'        => 0.90,
                'default_value_text' => '0.90',
                'rationale_text'     => 'Множитель времени крафта для всех членов фракции, пока активен фракц-бафф. 0.90 = −10% времени крафта. Мультипликативно с верстаком/сытостью/специализацией.',
                'effect_text'        => 'GenericCraftActionStart::calculateCraftingDuration умножает на это для членов фракции с buff_until > now (FactionProjectService::craftTimeMultiplierFor).',
                'above_effect_text'  => 'При 0.99 эффект −1% незаметен → бафф не ощущается наградой за вклад.',
                'below_effect_text'  => 'При 0.50 −50% крафта стакается с верстаком/спец/сытостью → крафт near-instant, ломает прогрессию таймингов.',
                'recommended_min'    => '0.75',
                'recommended_max'    => '0.97',
                'hard_min'           => '0.30',
                'hard_max'           => '1.00',
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
                'faction.project.enabled',
                'faction.project.threshold',
                'faction.project.deposit_gold',
                'faction.project.buff_duration_hours',
                'faction.project.craft_time_multiplier',
            ])
            ->delete();
    }
}
