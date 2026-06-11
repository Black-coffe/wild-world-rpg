<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Attributes\HandlerKey;
use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — обчислення health/tired damage для подій типу:
 * Hurricane, NightAttacks, Epidemic, FlashForestFire, Snowfall, SpringFlood,
 * Tremor, volcanic_eruption, Sandstorm, Fever.
 *
 * Об'єднує логіку 9 hand-rolled handler'ів через effect_params.
 *
 * Підтримувані params (з WorldEvents.php):
 *   - damage_target           : 'health' | 'tired' | 'random_h_or_t' | 'both' | 'biome_type_specific'
 *   - state_modifier          : array{base_idle: float, biome_idle: float, biome_active: float}
 *   - level_scaling           : bool — застосувати (100-level)/100 коефіцієнт
 *   - biome_factor            : bool — множити на (danger+difficulty)/20
 *   - random_factor           : [min, max] — множник rand(min*100..max*100)/100
 *   - damage_range            : [min, max] — фіксована вилка damage (без effect_value)
 *   - base_damage_mul         : float — додатковий множник base damage (Volcanic = 10)
 *   - tired_ratio             : float — для damage_target='both', tired = damage * ratio
 *   - two_stage_chance        : float — додатковий первинний фільтр (0..1) перед state_modifier
 *   - biome_type_specific     : array — для Fever (wet/dry/default)
 *   - health_loss_range       : [min, max] — для Epidemic (з'єднується з biome_type_specific)
 *   - tired_loss_range        : [min, max] — для Epidemic
 *   - sample_percent          : float — Epidemic обробляє 1% chars (sampling робить dispatcher)
 *   - time_window             : [HH:MM, HH:MM] — лише в нічні години (NightAttacks)
 *   - sleeping_player_skip    : int — skip якщо last_seen > N годин тому
 *   - attr_drain_pool         : list<string> — Sandstorm drain'ить 1 з атрибутів
 *   - attr_drain_value        : float — на скільки drain'ить (наприклад 0.01)
 *
 * Усі формули збережено 1:1 з оригінальних handler'ів. Зміни в balance — окремо.
 */
#[HandlerKey(
    key: 'damage_health',
    displayName: 'Урон здоровью',
    description: 'Снижает HP и/или выносливость персонажа за тик события (Hurricane, Snowfall, Volcanic, NightAttacks, Epidemic, FlashForestFire, SpringFlood, Tremor, Sandstorm, Fever).',
    inputSchema: [
        ['name' => 'damage_target', 'type' => 'enum', 'values' => ['health', 'tired', 'random_h_or_t', 'both', 'biome_type_specific']],
        ['name' => 'health_loss_range', 'type' => 'int_range', 'min' => 0, 'max' => 100],
        ['name' => 'tired_loss_range', 'type' => 'int_range', 'min' => 0, 'max' => 100],
        ['name' => 'damage_range', 'type' => 'int_range', 'min' => 0, 'max' => 100],
        ['name' => 'state_modifier', 'type' => 'state_modifier'],
        ['name' => 'time_window', 'type' => 'time_range'],
        ['name' => 'two_stage_chance', 'type' => 'float', 'min' => 0.0, 'max' => 1.0],
    ],
)]
final class DamageHealthEffect implements EventEffectInterface
{
    public function compute(array|\App\Entities\CharacterEntity $character, array $eventConfig, array $activeEvent, array $context): array
    {
        $params = $eventConfig['effect_params'] ?? [];

        // ====================================================
        // Фільтр 1: time_window (NightAttacks: 20:00-05:00)
        // ====================================================
        if (isset($params['time_window'])) {
            $now = $context['now_time'] ?? date('H:i');
            [$start, $end] = $params['time_window'];
            $inWindow = ($now >= $start || $now <= $end);
            if (!$inWindow) {
                return EffectResultFactory::skipped('Поза time_window');
            }
        }

        // ====================================================
        // Фільтр 2: sleeping_player_skip (NightAttacks)
        // ====================================================
        if (isset($params['sleeping_player_skip'])) {
            $lastSeenH = $context['last_seen_hours_ago'] ?? null;
            if ($lastSeenH !== null && $lastSeenH > $params['sleeping_player_skip']) {
                return EffectResultFactory::skipped("Гравець спить ({$lastSeenH}г тому last_seen)");
            }
        }

        // ====================================================
        // Фільтр 3: two_stage_chance (Fever, Epidemic — первинний 50%)
        // ====================================================
        if (isset($params['two_stage_chance'])) {
            $roll = mt_rand(1, 100) / 100.0;
            if ($roll > $params['two_stage_chance']) {
                return EffectResultFactory::skipped('Не пройшов two_stage chance');
            }
        }

        // ====================================================
        // Фільтр 4: state_modifier (база захищає, gather/explore = повний удар)
        // ====================================================
        $stateModifier = $params['state_modifier'] ?? ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0];
        $stateCoef     = EffectResultFactory::stateCoefficient($stateModifier, $context);

        if ($stateCoef <= 0.0) {
            return EffectResultFactory::skipped('Захищений (state_coef=0)');
        }

        // Якщо state_coef є chance (Epidemic) — додатковий roll
        if ($stateCoef <= 1.0 && ($params['state_modifier_is_chance'] ?? false)) {
            $roll = mt_rand(1, 100) / 100.0;
            if ($roll > $stateCoef) {
                return EffectResultFactory::skipped("Не пройшов state-chance ({$stateCoef})");
            }
            $stateCoef = 1.0;
        }

        // ====================================================
        // BUGFIX v0.51.16x — режим незалежних range'ів health/tired (Epidemic).
        // Конфіг Epidemic визначає health_loss_range [1,5] / tired_loss_range [1,3], але
        // F7.2-рефактор їх НІКОЛИ не читав → код провалювався на effect_value (≈99 у БД події)
        // × state_coef ≈ 50 HP/тик замість 1–5 → low-level персонажів вбивало (prod, SarCasM).
        // Тут: втрачаємо rand(health_loss_range) HP і rand(tired_loss_range) виносливості
        // НЕЗАЛЕЖНО, ×state_coef (0.06 база / 0.5 idle-біом / 0.9 gather-біом), ×protection (-50%).
        // ====================================================
        $p = is_array($params) ? $params : [];
        if (isset($p['health_loss_range']) || isset($p['tired_loss_range'])) {
            $mul   = $stateCoef * (!empty($context['has_protection_item']) ? 0.5 : 1.0);
            $hLoss = isset($p['health_loss_range']) ? self::lossFromRange($p['health_loss_range'], $mul) : 0.0;
            $tLoss = isset($p['tired_loss_range'])  ? self::lossFromRange($p['tired_loss_range'],  $mul) : 0.0;
            if ($hLoss <= 0.0 && $tLoss <= 0.0) {
                return EffectResultFactory::skipped('Урон 0 після state_coef');
            }
            $logParts = [];
            if ($hLoss > 0.0) {
                $logParts[] = "-{$hLoss} HP";
            }
            if ($tLoss > 0.0) {
                $logParts[] = "-{$tLoss} вынос.";
            }
            $currentHealth = (float) ($character['health'] ?? 100);

            return EffectResultFactory::make([
                'applied'          => true,
                'health_delta'     => -$hLoss,
                'tired_delta'      => -$tLoss,
                'attribute_deltas' => [],
                'log_summary'      => implode(', ', $logParts),
                'magnitude'        => [
                    'health_loss_percent' => $currentHealth > 0 ? round($hLoss / $currentHealth * 100, 2) : 0.0,
                    'health_after'        => max(0.01, $currentHealth - $hLoss),
                    'effect_kind'         => 'damage_health',
                ],
            ]);
        }

        // ====================================================
        // Обчислення базового damage
        // ====================================================
        $effectValue = (float)($activeEvent['effect_value'] ?? $eventConfig['effect_params']['effect_value'] ?? 30.0);

        // damage_range має пріоритет (Snowfall: rand(1, 90))
        if (isset($params['damage_range'])) {
            [$dmgMin, $dmgMax] = $params['damage_range'];
            $base = (float)mt_rand((int)$dmgMin, (int)$dmgMax);
        } else {
            $base = $effectValue;
        }

        // base_damage_mul (Volcanic: ×10)
        if (isset($params['base_damage_mul'])) {
            $base *= (float)$params['base_damage_mul'];
        }

        // level_scaling (більший level → менший damage)
        if (!empty($params['level_scaling'])) {
            $charLevel   = max(1, (int)($character['level'] ?? 1));
            $levelFactor = max(1, 100 - $charLevel) / 100.0;
            $base *= $levelFactor;
        }

        // E17 (ADR-117) — уровневый tier-модификатор: новичков щадит, ветеранов испытывает.
        // Dormant (killswitch off) → 1.0 = byte-identical (fixture-fence-safe).
        $base *= (new \App\Services\Events\EventLevelTierService())->harmFactorFor($character);

        // biome_factor (важче в небезпечних біомах)
        if (!empty($params['biome_factor'])) {
            $biome      = $context['biome'] ?? null;
            $danger     = (int)($biome['danger_level'] ?? 5);
            $difficulty = (int)($biome['survival_difficulty'] ?? 5);
            $base *= ($danger + $difficulty) / 20.0;
        }

        // random_factor (±50% за замовчуванням)
        if (isset($params['random_factor'])) {
            [$rMin, $rMax] = $params['random_factor'];
            $base *= mt_rand((int)($rMin * 100), (int)($rMax * 100)) / 100.0;
        }

        // Final damage з урахуванням state coefficient
        $damage = round($base * $stateCoef, 2);

        // Protection item (F7.7) — -50% урону
        if (!empty($context['has_protection_item'])) {
            $damage = round($damage * 0.5, 2);
        }

        // ====================================================
        // Розподіл damage по health/tired згідно damage_target
        // ====================================================
        $healthDelta = 0.0;
        $tiredDelta  = 0.0;
        $logParts    = [];

        $target = $params['damage_target'] ?? 'health';

        switch ($target) {
            case 'health':
                $healthDelta = -$damage;
                $logParts[] = "-{$damage} HP";
                break;

            case 'tired':
                $tiredDelta = -$damage;
                $logParts[] = "-{$damage} вынос.";
                break;

            case 'random_h_or_t':
                if (mt_rand(0, 1) === 0) {
                    $healthDelta = -$damage;
                    $logParts[] = "-{$damage} HP";
                } else {
                    $tiredDelta = -$damage;
                    $logParts[] = "-{$damage} вынос.";
                }
                break;

            case 'both':
                $tRatio      = (float)($params['tired_ratio'] ?? 1.0);
                $healthDelta = -$damage;
                $tiredDelta  = -round($damage * $tRatio, 2);
                $logParts[]  = "-{$damage} HP, -" . abs($tiredDelta) . " вынос.";
                break;

            case 'biome_type_specific':
                // Fever: wet → health, dry → tired, default → both small
                $biomeType = $context['biome']['biome_type'] ?? 'default';
                $spec      = $params['biome_type_specific'] ?? [];
                $rules     = $spec[$biomeType] ?? $spec['default'] ?? [];

                foreach ($rules as $stat => $range) {
                    [$min, $max] = $range;
                    $loss        = mt_rand((int)$min, (int)$max);
                    if ($stat === 'health') {
                        $healthDelta = -$loss;
                        $logParts[]  = "-{$loss} HP";
                    } elseif ($stat === 'tired') {
                        $tiredDelta = -$loss;
                        $logParts[] = "-{$loss} вынос.";
                    }
                }
                break;
        }

        // ====================================================
        // Sandstorm: додаткова drain'ка випадкового атрибуту
        // ====================================================
        $attributeDeltas = [];
        if (isset($params['attr_drain_pool']) && isset($params['attr_drain_value'])) {
            $pool         = $params['attr_drain_pool'];
            $attr         = $pool[array_rand($pool)];
            $val          = -(float)$params['attr_drain_value'];
            $attributeDeltas[$attr] = $val;
            $logParts[]   = "{$attr}: {$val}";
        }

        // ====================================================
        // Magnitude metrics для throttle override
        // ====================================================
        $currentHealth = (float)($character['health'] ?? 100);
        $magnitude     = [
            'health_loss_percent'    => $currentHealth > 0 ? round(abs($healthDelta) / $currentHealth * 100, 2) : 0.0,
            'health_after'           => max(0.01, $currentHealth + $healthDelta),
            'effect_kind'            => 'damage_health',
        ];

        return EffectResultFactory::make([
            'applied'           => true,
            'health_delta'      => $healthDelta,
            'tired_delta'       => $tiredDelta,
            'attribute_deltas'  => $attributeDeltas,
            'log_summary'       => implode(', ', $logParts),
            'magnitude'         => $magnitude,
        ]);
    }

    /**
     * `rand(min, max) × $mul`, округлено до 0.01. `$range` — `[min, max]` із конфіга (mixed →
     * захищено: не array / <2 елементів / нечислові / max≤0 → 0.0).
     */
    private static function lossFromRange(mixed $range, float $mul): float
    {
        if (!is_array($range) || count($range) < 2) {
            return 0.0;
        }
        $min = is_numeric($range[0] ?? 0) ? (int) ($range[0] ?? 0) : 0;
        $max = is_numeric($range[1] ?? 0) ? (int) ($range[1] ?? 0) : $min;
        if ($max < $min) {
            $max = $min;
        }
        if ($max <= 0) {
            return 0.0;
        }

        return round(mt_rand($min, $max) * $mul, 2);
    }
}
