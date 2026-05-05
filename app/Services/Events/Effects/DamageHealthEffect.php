<?php

declare(strict_types=1);

namespace App\Services\Events\Effects;

use App\Services\Events\EventEffectInterface;

/**
 * F7.2 — вычислення health/tired damage для событий типа:
 * Hurricane, NightAttacks, Epidemic, FlashForestFire, Snowfall, SpringFlood,
 * Tremor, volcanic_eruption, Sandstorm, Fever.
 *
 * Об'єднуесть логікв 9 hand-rolled handler'ів через effect_params.
 *
 * Підтримувани params (з WorldEvents.php):
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
 *   - health_loss_range       : [min, max] — для Epidemic (з'єднується с biome_type_specific)
 *   - tired_loss_range        : [min, max] — для Epidemic
 *   - sample_percent          : float — Epidemic обробляесть 1% chars (sampling робить dispatcher)
 *   - time_window             : [HH:MM, HH:MM] — лише в нічни часа (NightAttacks)
 *   - sleeping_player_skip    : int — skip если last_seen > N часов назад
 *   - attr_drain_pool         : list<string> — Sandstorm drain'ить 1 с атрибутів
 *   - attr_drain_value        : float — на скільки drain'ить (например 0.01)
 *
 * Все формули збережено 1:1 с оригінальних handler'ів. Зміни в balance — окремо.
 */
final class DamageHealthEffect implements EventEffectInterface
{
    public function compute(array $character, array $eventConfig, array $activeEvent, array $context): array
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
                return EffectResultFactory::skipped("Игрок спить ({$lastSeenH}г томв last_seen)");
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
        // Фільтр 4: state_modifier (база захищаесть, gather/explore = повний удар)
        // ====================================================
        $stateModifier = $params['state_modifier'] ?? ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0];
        $stateCoef     = EffectResultFactory::stateCoefficient($stateModifier, $context);

        if ($stateCoef <= 0.0) {
            return EffectResultFactory::skipped('Захищений (state_coef=0)');
        }

        // Если state_coef есть chance (Epidemic) — додатковий roll
        if ($stateCoef <= 1.0 && ($params['state_modifier_is_chance'] ?? false)) {
            $roll = mt_rand(1, 100) / 100.0;
            if ($roll > $stateCoef) {
                return EffectResultFactory::skipped("Не пройшов state-chance ({$stateCoef})");
            }
            $stateCoef = 1.0;
        }

        // ====================================================
        // Вычислення базового damage
        // ====================================================
        $effectValue = (float)($activeEvent['effect_value'] ?? $eventConfig['effect_params']['effect_value'] ?? 30.0);

        // damage_range маесть пріоритет (Snowfall: rand(1, 90))
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

        // biome_factor (важче в небезпечних биомах)
        if (!empty($params['biome_factor'])) {
            $biome      = $context['biome'] ?? null;
            $danger     = (int)($biome['danger_level'] ?? 5);
            $difficulty = (int)($biome['survival_difficulty'] ?? 5);
            $base *= ($danger + $difficulty) / 20.0;
        }

        // random_factor (±50% за умолчануванням)
        if (isset($params['random_factor'])) {
            [$rMin, $rMax] = $params['random_factor'];
            $base *= mt_rand((int)($rMin * 100), (int)($rMax * 100)) / 100.0;
        }

        // Final damage с урахуванням state coefficient
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
        // Sandstorm: додаткова drain'ка случаового атрибуту
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
}
