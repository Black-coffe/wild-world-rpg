<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * W5 (ADR-064) — Combat-drone build. 7 GameSettings `drone.combat.*`
 * (category=combat) с rich rationale (ADR-024).
 *
 * Резолюции (см. ADR-064):
 *  - enabled=true (killswitch). При false: кнопка «🛡 Боевой дрон» в Перс/StandardCrafting
 *    остаётся в lock-state; CombatDroneActivateAction отвергает; bonus не подмешивается
 *    в getDefenseProfile.
 *  - initiative_bonus_percent=12 — мягче WatchTower 8% baseline, но не absolute (cap=25).
 *  - activation_minutes=30 — time-window после запуска, защитный bonus активен это время.
 *  - battery_drain_per_launch=100, battery_max=100 — 1 активация на полный заряд.
 *  - base_charge_minutes_per_full=360 (6 ч, slower vs repair 4ч — premium combat-tool).
 *  - max_combined_initiative_percent=25 — soft cap для WatchTower+Combat combined bonus.
 *
 * Idempotent: пропускает существующие setting_key.
 */
class W5SeedDroneCombatGameSettings extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'setting_key'        => 'drone.combat.enabled',
                'category'           => 'combat',
                'value_type'         => 'bool',
                'value_bool'         => 1,
                'default_value_text' => 'true',
                'rationale_text'     => 'Killswitch слоя Combat-drone (W5 ADR-064). true — крафт DroneCombat доступен, activate-action отрабатывает (выставляет characters.combat_drone_active_until = NOW + activation_minutes), recharge-cron тикает по 4 типам (scout + cargo + repair + combat), DefenseStructureService подмешивает bonus в initiative_bonus профиля защитника. false — кнопка «🛡 Боевой дрон» в Перс остаётся в lock-state (правило #4 CLAUDE.md), action отвергает; скрафченные инстансы замораживаются. Не разрушает WatchTower S26b (initiative_bonus от вышки остаётся живым параллельным путём).',
                'effect_text'        => 'DroneService::combatIsEnabled() читает это значение. Влияет: видимость кнопки в CharacterService::showCharacter, gate в CombatDroneActivateAction, no-op флаг для combat-type в DroneRechargeCron, branch в DefenseStructureService::getDefenseProfile (skip drone-bonus add если disabled).',
                'above_effect_text'  => 'true: Combat-drone работает как описано в ADR-064 (по запросу, 30-мин window, +12% bonus до cap 25% combined с WatchTower).',
                'below_effect_text'  => 'false: фича выключена, ресурсы потраченные на крафт DroneCombat не возвращаются (инстансы остаются в crafted_items_log с замороженным durability_count). WatchTower S26b initiative-bonus работает без изменений.',
                'recommended_min'    => null,
                'recommended_max'    => null,
                'hard_min'           => null,
                'hard_max'           => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.initiative_bonus_percent',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 12,
                'default_value_text' => '12',
                'rationale_text'     => 'На сколько процентов растёт инициатива защитника-владельца, если у него активен Combat-drone (combat_drone_active_until > NOW). 12 — мягче WatchTower 8 в одиночку (combined cap 25 защищает от monopoly). Презюмируется добавочно к tower-bonus: при WT+CD одновременно sum = 8 + 12 = 20 (под cap). Активация — out-of-band callback, без RNG. Эффект применяется через тот же hook `$defense[initiative_bonus]` что и WatchTower (PvpRoundOrchestrator::determineInitiative, deterministic умножение).',
                'effect_text'        => 'DroneService::combatInitiativeBonusPercent() читает значение. DefenseStructureService::getDefenseProfile() добавляет к tower-bonus если combat_drone_active_until > NOW для defender. Финальный bonus = min(tower + combat, max_combined_initiative_percent) / 100.0. PvpRoundOrchestrator::determineInitiative умножает i-score на (1 + bonus). RNG-fence: zero new mt_rand.',
                'above_effect_text'  => 'При 30: defender almost always first-strike при активном дроне → ощутимый перекос, П2/П3 P2W feel. Cap 25 страхует, но recommended limit 20 не перекрывать.',
                'below_effect_text'  => 'При 0: Combat-drone не даёт боевого преимущества, остаётся только battery cost + activation flow → BUILT-BUT-DEAD.',
                'recommended_min'    => '5',
                'recommended_max'    => '20',
                'hard_min'           => '0',
                'hard_max'           => '40',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.activation_minutes',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 30,
                'default_value_text' => '30',
                'rationale_text'     => 'Сколько минут после активации Combat-drone bonus считается активным. Запуск выставляет characters.combat_drone_active_until = NOW + activation_minutes. Любая incoming PvP-атака в это окно — defender получает initiative_bonus. По истечении (lazy expiry) — нужно повторно активировать. 30 мин — окно для «защити меня сейчас», достаточно чтобы выйти из боя/завершить поход с buff; недостаточно чтобы быть permanent first-strike.',
                'effect_text'        => 'DroneService::combatActivationMinutes() читает значение. CombatDroneActivateAction вычисляет $until = date(Y-m-d H:i:s, time() + activation_minutes * 60); UPDATE characters SET combat_drone_active_until = $until WHERE id = $charId. DefenseStructureService читает поле через CharacterModel::find и сравнивает с NOW.',
                'above_effect_text'  => 'При 240 (4 ч): 1 активация = почти постоянный buff в течение activity-сессии, recharge становится бесполезным → BUILT-BUT-DEAD механика battery.',
                'below_effect_text'  => 'При 1 мин: окно слишком короткое → надо точно угадать момент атаки → UX-frustration, фича теряет смысл.',
                'recommended_min'    => '10',
                'recommended_max'    => '60',
                'hard_min'           => '1',
                'hard_max'           => '720',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.battery_drain_per_launch',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Сколько единиц durability_count вычитается из combat-инстанса при активации. 100 = равно battery_max → одна активация на полный заряд. Симметрично scout/cargo/repair: предсказуемая стоимость, исключает spam. Cooldown = battery recharge time (6 ч). За день P2 (2 ч on-base) получает ~0.33 charge → activation раз/3 дня; P8 full-time on-base = 4 activations/день.',
                'effect_text'        => 'DroneService::combatBatteryDrainPerLaunch() читает значение. CombatDroneActivateAction проверяет durability_count >= drain; если нет — отвергает с alert «нужна зарядка». После успеха: UPDATE crafted_items_log SET durability_count = durability_count - drain WHERE id = log_id.',
                'above_effect_text'  => 'При 200 (выше battery_max=100): ни один скрафченный combat-дрон не активируется → BUILT-BUT-DEAD.',
                'below_effect_text'  => 'При 10: 10 активаций на полный заряд → infinite-buff → defender almost always first-strike → ломает PvP-баланс.',
                'recommended_min'    => '50',
                'recommended_max'    => '100',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.battery_max',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 100,
                'default_value_text' => '100',
                'rationale_text'     => 'Макс. заряд (= durability_count) одного combat-инстанса. 100 = одна активация на полной зарядке. Каждый новый скрафченный DroneCombat получает durability_count = battery_max на crafted_items_log row.',
                'effect_text'        => 'crafted_items.DroneCombat.durability_count=100 при крафте. DroneRechargeCron generalize loop clamp до battery_max per type.',
                'above_effect_text'  => 'При 500: 5 активаций на полный заряд → подрывает rate-limit, защитник может постоянно держать buff.',
                'below_effect_text'  => 'При 50 (ниже drain=100): ни один combat-дрон не активируется → BUILT-BUT-DEAD.',
                'recommended_min'    => '50',
                'recommended_max'    => '300',
                'hard_min'           => '1',
                'hard_max'           => '10000',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.base_charge_minutes_per_full',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 360,
                'default_value_text' => '360',
                'rationale_text'     => 'За сколько минут on_base combat-дрон заряжается с 0 до battery_max. 360 мин = 6 часов (repair=4 ч, combat медленнее — premium combat-tool). Rate = 100/360 ≈ 0.28 charge/мин. P2-friendly: 30 мин/день on_base = ~8 charge/день → 12 дней до full → 1 активация/12 дней. Active 2 ч/день = ~1 активация/3 дня. На full timer 24/7 on-base = 4 activations/день — естественный rate-limit для эндгейма.',
                'effect_text'        => 'DroneRechargeCron generalize (W5) per-type re-read base_charge_minutes_per_full. Lazy fallback при read учитывает время с last_charge если cron пропустил тики.',
                'above_effect_text'  => 'При 1440 (24 ч/full): одна активация/день — мало для эндгейма, gold-sink крафта DroneCombat не оправдан.',
                'below_effect_text'  => 'При 60 мин: 24 активации/день → near-infinite buff → ломает PvP-баланс через permanent defender first-strike.',
                'recommended_min'    => '180',
                'recommended_max'    => '720',
                'hard_min'           => '1',
                'hard_max'           => '10080',
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'setting_key'        => 'drone.combat.max_combined_initiative_percent',
                'category'           => 'combat',
                'value_type'         => 'int',
                'value_int'          => 25,
                'default_value_text' => '25',
                'rationale_text'     => 'Soft cap для combined initiative-bonus (WatchTower + Combat drone). DefenseStructureService суммирует tower_bonus + drone_bonus и берёт min(sum, max_combined_initiative_percent). 25 = 8 (tower default) + 12 (combat default) = 20 < 25 → оба эффекта работают. Если оба знaка повышены админом (например tower=20 + combat=20 = 40) — cap 25 страхует от absolute first-strike monopoly. Зеркало defense.total_damage_reduction_max_percent (S26 cap для WoodenWall stacking).',
                'effect_text'        => 'DroneService::combatMaxCombinedInitiativePercent() читает значение. DefenseStructureService::getDefenseProfile() применяет: $combined = min($tower_bonus + $drone_bonus, $cap); $profile[initiative_bonus] = $combined / 100.0.',
                'above_effect_text'  => 'При 60: суммарный buff до 60% → defender absolute first-strike при любом combat-build, лом PvP.',
                'below_effect_text'  => 'При 5 (ниже tower 8): cap режет tower-bonus до 5 → S26b WatchTower фактически занижен → жалобы игроков построивших вышки.',
                'recommended_min'    => '15',
                'recommended_max'    => '35',
                'hard_min'           => '0',
                'hard_max'           => '60',
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
                'drone.combat.enabled',
                'drone.combat.initiative_bonus_percent',
                'drone.combat.activation_minutes',
                'drone.combat.battery_drain_per_launch',
                'drone.combat.battery_max',
                'drone.combat.base_charge_minutes_per_full',
                'drone.combat.max_combined_initiative_percent',
            ])
            ->delete();
    }
}
