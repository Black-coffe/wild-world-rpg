<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E17 Ф2 (ROADMAP-100, ADR-117) — tier ПОЛЕЗНЫХ эффектов событий (boonFactor).
 *
 * E17 Ф1 затиерил только ВРЕД (harmFactor: новичок ×0.5 / ветеран ×1.3). Полезные эффекты
 * остались плоскими — асимметрия. Ф2 добавляет симметричный boonFactor: новичку события
 * ЩЕДРЕЕ (лечение/редкий ресурс ×1.3 — облегчает стену L1→L2, находка среза E12), ветерану
 * ×1.0 (без faucet — флат-бонус и так тривиален при 10-16M; эконом-инвариант П8).
 *
 * Мир щадит новичка (вред ×0.5 + польза ×1.3) и испытывает ветерана (вред ×1.3 + польза ×1.0).
 *
 * ОТДЕЛЬНЫЙ killswitch `world.events.boon_tier_enabled` (НЕ реюз harm-killswitch, который УЖЕ
 * активен на проде) → боны выкатываются dormant и активируются после ревью владельца, не трогая
 * живой harm-tier. Default OFF → boonFactor=1.0 = byte-identical (фикстуры эффектов зелёные).
 * Бэнды (newbie_max/veteran_min) реюзятся из E17 Ф1. category=world. Idempotent.
 */
class Adr117EventBoonTierSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'world.events.boon_tier_enabled',
                'value_type'         => 'bool',
                'value_bool'         => 0,
                'default_value_text' => 'false',
                'rationale_text'     => 'Killswitch уровневого tier\'а ПОЛЕЗНЫХ эффектов событий (ADR-117 Ф2). false (default) — польза плоская (как до E17 Ф2). true — новичку события дают больше (лечение/редкий ресурс ×boon_newbie), ветерану — без изменений. Отдельный от harm-killswitch (тот уже live).',
                'effect_text'        => 'EventLevelTierService::boonEnabled. true → HealEffect/RareResourceGrantEffect множат выдачу на boonFactor по уровню персонажа.',
                'above_effect_text'  => 'true: симметрия с harm — мир щадит новичка (меньше вреда + больше пользы), испытывает ветерана.',
                'below_effect_text'  => 'false: мгновенный откат бон-tier\'а без редеплоя; эффекты byte-identical (boonFactor=1.0).',
                'hard_min'           => '0',
                'hard_max'           => '1',
            ],
            [
                'setting_key'        => 'world.events.tier_boon_newbie_factor',
                'value_type'         => 'float',
                'value_float'        => 1.30,
                'default_value_text' => '1.30',
                'rationale_text'     => 'Множитель ПОЛЬЗЫ для новичка (level ≤ tier_newbie_max_level, default 9). +30% к лечению/редкому ресурсу от событий — мягкая помощь раннему выживанию и прогрессу (стена L1→L2, срез E12).',
                'effect_text'        => 'boonFactor для новичка. HealEffect.amount / RareResourceGrantEffect.amount × этот фактор. Детерминир.',
                'above_effect_text'  => 'При 2.00 (+100%) — события слишком сильно вытягивают новичка, обесценивают добычу/крафт.',
                'below_effect_text'  => 'При 1.05 (+5%) — помощь незаметна, не двигает раннюю воронку.',
                'recommended_min'    => '1.10',
                'recommended_max'    => '1.60',
                'hard_min'           => '1.00',
                'hard_max'           => '3.00',
            ],
            [
                'setting_key'        => 'world.events.tier_boon_veteran_factor',
                'value_type'         => 'float',
                'value_float'        => 1.00,
                'default_value_text' => '1.00',
                'rationale_text'     => 'Множитель ПОЛЬЗЫ для ветерана (level ≥ tier_veteran_min_level, default 25). По умолчанию 1.0 — без инфляции: флат-бонус события (лечение/ресурс) и так тривиален для L25+. Tunable, если захотим слегка наградить/урезать ветеранов.',
                'effect_text'        => 'boonFactor для ветерана. ×1.0 = без изменений. Детерминир.',
                'above_effect_text'  => 'При 1.30 — ветераны получают +30% пользы; для редких ресурсов может ускорить эндгейм-инфляцию (следить за П8).',
                'below_effect_text'  => 'При 0.80 — события дают ветерану меньше пользы (наказание за уровень); может ощущаться несправедливо.',
                'recommended_min'    => '0.90',
                'recommended_max'    => '1.20',
                'hard_min'           => '0.50',
                'hard_max'           => '2.00',
            ],
        ];

        $defaults = [
            'category'        => 'world',
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
            'world.events.boon_tier_enabled',
            'world.events.tier_boon_newbie_factor',
            'world.events.tier_boon_veteran_factor',
        ])->delete();
    }
}
