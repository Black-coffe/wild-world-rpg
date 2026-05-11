<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\WorldEvents;

/**
 * Death-validation batch 5 — anti-regression guard: ни одно `damage_health`-событие не должно
 * наносить за один тик больше {@see MAX_PER_TICK_LOSS}% от max HP (= 100) — ни по health, ни по
 * выносливости.
 *
 * Ловит ровно класс багов:
 *   - «Эпидемия» — `effect_value`(≈99 в БД) не калибровался под F7.2-движок → ~50 HP/тик вместо 1–5;
 *   - «volcanic_eruption» — `effect_value`(96) × `base_damage_mul`(10) × модификаторы ≈ ~1400 HP/тик.
 *
 * Воспроизводит worst-case формулу {@see \App\Services\Events\Effects\DamageHealthEffect::compute()}:
 * level 1, без protection_item, макс biome danger+difficulty (=20 → biome_factor=1.0), макс random_factor,
 * состояние biome_active. Если кто-то добавит damage_health-событие с large range / base_damage_mul /
 * raw effect_value-путём — тест упадёт с подсказкой.
 *
 * @internal
 */
final class WorldEventsDamageBoundsTest extends CIUnitTestCase
{
    /** max HP персонажа: `characters.health DEFAULT 100.00`, отдельной колонки max_health нет. */
    private const MAX_HP = 100.0;

    /** Потолок урона за тик — 10% от max HP. (X согласован: 10 HP/тик по health и по tired.) */
    private const MAX_PER_TICK_LOSS = 10.0;

    public function testNoDamageHealthEventExceedsPerTickBound(): void
    {
        $cfg     = new WorldEvents();
        $checked = 0;

        foreach ($cfg->events as $key => $event) {
            if ($event['effect_kind'] !== 'damage_health') {
                continue;
            }
            $params = $event['effect_params'];
            [$maxHealth, $maxTired] = $this->theoreticalMaxLossPerTick($params);

            $this->assertLessThanOrEqual(
                self::MAX_PER_TICK_LOSS,
                $maxHealth,
                "Событие '{$key}': макс. урон по HP за тик ≈ {$maxHealth} > " . self::MAX_PER_TICK_LOSS
                . ' (= 10% от max HP ' . self::MAX_HP . '). Используй health_loss_range/damage_range в пределах потолка,'
                . ' без base_damage_mul и без level/biome/random на raw effect_value.'
            );
            $this->assertLessThanOrEqual(
                self::MAX_PER_TICK_LOSS,
                $maxTired,
                "Событие '{$key}': макс. потеря выносливости за тик ≈ {$maxTired} > " . self::MAX_PER_TICK_LOSS . '.'
            );
            $checked++;
        }

        $this->assertGreaterThanOrEqual(8, $checked, 'Ожидали ≥ 8 damage_health-событий — проверь, что тест их находит.');
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: float, 1: float} [maxHealthLoss, maxTiredLoss] за один тик в worst-case
     */
    private function theoreticalMaxLossPerTick(array $params): array
    {
        $sm      = is_array($params['state_modifier'] ?? null) ? $params['state_modifier'] : ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0];
        $coefs   = array_filter(array_values($sm), 'is_numeric');
        $maxCoef = $coefs === [] ? 1.0 : (float) max($coefs);
        if (!empty($params['state_modifier_is_chance'])) {
            $maxCoef = max($maxCoef, 1.0); // после прохождения state-chance roll'а stateCoef → 1.0
        }

        // 1) явные range'ы health/tired (Epidemic + все rebalanced batch 5)
        if (isset($params['health_loss_range']) || isset($params['tired_loss_range'])) {
            $h = isset($params['health_loss_range']) ? $this->rangeMax($params['health_loss_range']) * $maxCoef : 0.0;
            $t = isset($params['tired_loss_range']) ? $this->rangeMax($params['tired_loss_range']) * $maxCoef : 0.0;

            return [$h, $t];
        }

        // 2) biome_type_specific (Fever) — урон НЕ множится на state_coef (см. compute())
        if (isset($params['biome_type_specific']) && is_array($params['biome_type_specific'])) {
            $h = 0.0;
            $t = 0.0;
            foreach ($params['biome_type_specific'] as $rules) {
                if (!is_array($rules)) {
                    continue;
                }
                foreach ($rules as $stat => $range) {
                    $m = $this->rangeMax($range);
                    if ($stat === 'health') {
                        $h = max($h, $m);
                    }
                    if ($stat === 'tired') {
                        $t = max($t, $m);
                    }
                }
            }

            return [$h, $t];
        }

        // 3) raw effect_value / damage_range путь (legacy; после batch 5 им не пользуется ни одно damage_health-событие)
        $base = isset($params['damage_range'])
            ? $this->rangeMax($params['damage_range'])
            : (is_numeric($params['effect_value'] ?? null) ? (float) $params['effect_value'] : 30.0);
        if (is_numeric($params['base_damage_mul'] ?? null)) {
            $base *= (float) $params['base_damage_mul'];
        }
        // level_scaling: при level 1 → ×(99/100) ≈ 1.0 (worst-case низкоуровневый) — берём 1.0 консервативно.
        // biome_factor: max (danger+difficulty)/20 = 20/20 = 1.0.
        if (isset($params['random_factor'])) {
            $base *= $this->rangeMax($params['random_factor']);
        }
        $dmg = $base * $maxCoef;

        $target = is_string($params['damage_target'] ?? null) ? $params['damage_target'] : 'health';
        if ($target === 'tired') {
            return [0.0, $dmg];
        }
        if ($target === 'both') {
            $tRatio = is_numeric($params['tired_ratio'] ?? null) ? (float) $params['tired_ratio'] : 1.0;

            return [$dmg, $dmg * $tRatio];
        }

        // 'health' | 'random_h_or_t' — оба стата теоретически могут получить полный урон
        return [$dmg, $target === 'random_h_or_t' ? $dmg : 0.0];
    }

    /** Макс из `[min, max]`-диапазона конфига (mixed → защищено: не array / пустой / нечисловые → 0.0). */
    private function rangeMax(mixed $range): float
    {
        if (!is_array($range) || $range === []) {
            return 0.0;
        }
        $nums = [];
        foreach ($range as $v) {
            if (is_numeric($v)) {
                $nums[] = (float) $v;
            }
        }

        return $nums === [] ? 0.0 : max($nums);
    }
}
