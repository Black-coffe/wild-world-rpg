<?php

namespace Tests\Unit\Services\Events\Effects;

use App\Services\Events\Effects\DamageHealthEffect;
use App\Services\Events\Effects\DamageResourcesEffect;
use App\Services\Events\Effects\EffectResultFactory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Матрица резолва player_state (on_base × gathering × exploring) + регрессия инцидента
 * «Метеоритный удар» 2026-08-09 21:21.
 *
 * Инцидент: игрок стоял на своей базе с запущенной задачей «Добыча». Резолв отдавал
 * 'biome_active' (коэффициент 1.0), и он получил −52% ресурсов, хотя текст события
 * обещает «нахождение на базе защищает полностью». Побочно это делало базу ХУЖЕ поля:
 * on_base+gather = 1.0 против biome_idle = 0.7.
 *
 * Инвариант: присутствие на базе доминирует над занятостью задачей — на базе игрок
 * всегда 'base_idle', чем бы он ни был занят.
 *
 * @internal
 */
final class PlayerStateResolutionTest extends CIUnitTestCase
{
    private const STATE_MODIFIER = ['base_idle' => 0.0, 'biome_idle' => 0.7, 'biome_active' => 1.0];

    // ============================================================
    // Матрица playerStateKey
    // ============================================================

    /** Полная матрица: на базе — всегда base_idle, чем бы игрок ни был занят. */
    public function testPlayerStateMatrix(): void
    {
        $matrix = [
            // [on_base, is_gathering, is_exploring, ожидаемое состояние]
            'на базе, без дела'         => [true,  false, false, 'base_idle'],
            'на базе + добыча'          => [true,  true,  false, 'base_idle'],
            'на базе + изучение'        => [true,  false, true,  'base_idle'],
            'на базе + добыча+изучение' => [true,  true,  true,  'base_idle'],
            'в поле, без дела'          => [false, false, false, 'biome_idle'],
            'в поле + добыча'           => [false, true,  false, 'biome_active'],
            'в поле + изучение'         => [false, false, true,  'biome_active'],
        ];

        foreach ($matrix as $case => [$onBase, $gathering, $exploring, $expected]) {
            $this->assertSame($expected, EffectResultFactory::playerStateKey([
                'on_base'      => $onBase,
                'is_gathering' => $gathering,
                'is_exploring' => $exploring,
            ]), "Состояние «{$case}»");
        }
    }

    /** Явный player_state в контексте имеет приоритет над флагами (контракт не изменился). */
    public function testExplicitPlayerStateWins(): void
    {
        $this->assertSame('biome_active', EffectResultFactory::playerStateKey([
            'player_state' => 'biome_active',
            'on_base'      => true,
        ]));
    }

    /** Пустой контекст → нейтральный biome_idle (дефолт не должен наказывать). */
    public function testEmptyContextIsBiomeIdle(): void
    {
        $this->assertSame('biome_idle', EffectResultFactory::playerStateKey([]));
    }

    // ============================================================
    // Порядок величин: база НИКОГДА не хуже поля
    // ============================================================

    /**
     * Ключевой инвариант, который и сломался: коэффициент на базе не может быть выше,
     * чем в поле при той же занятости.
     */
    public function testBaseIsNeverWorseThanField(): void
    {
        $onBaseGathering = EffectResultFactory::stateCoefficient(
            self::STATE_MODIFIER,
            ['on_base' => true, 'is_gathering' => true, 'is_exploring' => false]
        );
        $fieldIdle = EffectResultFactory::stateCoefficient(
            self::STATE_MODIFIER,
            ['on_base' => false, 'is_gathering' => false, 'is_exploring' => false]
        );
        $fieldGathering = EffectResultFactory::stateCoefficient(
            self::STATE_MODIFIER,
            ['on_base' => false, 'is_gathering' => true, 'is_exploring' => false]
        );

        $this->assertLessThanOrEqual($fieldIdle, $onBaseGathering);
        $this->assertLessThanOrEqual($fieldGathering, $onBaseGathering);
    }

    // ============================================================
    // Регрессия инцидента: MeteorImpact по игроку на базе
    // ============================================================

    /**
     * Ровно случай Father (char 1115, L45): на базе, задача «Добыча» in_work,
     * MeteorImpact 40% + ветеранский tier. Потеря обязана быть нулевой.
     */
    public function testMeteorImpactSparesPlayerOnBaseWhileGathering(): void
    {
        $r = (new DamageResourcesEffect())->compute(
            ['level' => 45],
            ['effect_params' => ['percent_per_event' => 40, 'state_modifier' => self::STATE_MODIFIER]],
            ['effect_value' => 40],
            ['on_base' => true, 'is_gathering' => true, 'is_exploring' => false]
        );

        $this->assertFalse($r['applied']);
        $this->assertSame(0.0, $r['resource_loss_percent']);
    }

    /** Тот же игрок, но в поле с добычей — полный удар остаётся (фикс не обезоруживает события). */
    public function testMeteorImpactStillHitsGathererInField(): void
    {
        $r = (new DamageResourcesEffect())->compute(
            ['level' => 45],
            ['effect_params' => ['percent_per_event' => 40, 'state_modifier' => self::STATE_MODIFIER]],
            ['effect_value' => 40],
            ['on_base' => false, 'is_gathering' => true, 'is_exploring' => false]
        );

        $this->assertTrue($r['applied']);
        $this->assertGreaterThan(0.0, $r['resource_loss_percent']);
    }

    /** Урон по здоровью на базе во время добычи — та же защита (Эпидемия/Фонящий туман/пожар). */
    public function testDamageHealthSparesPlayerOnBaseWhileGathering(): void
    {
        $r = (new DamageHealthEffect())->compute(
            ['health' => 100, 'tired' => 100, 'level' => 10],
            ['effect_params' => ['damage_target' => 'health', 'state_modifier' => self::STATE_MODIFIER]],
            ['effect_value' => 50],
            ['on_base' => true, 'is_gathering' => true, 'is_exploring' => false]
        );

        $this->assertFalse($r['applied']);
        $this->assertSame(0.0, $r['health_delta']);
    }

    /**
     * У событий с ненулевым base_idle (Эпидемия 0.06) защита базой частичная —
     * фикс не превращает её в полную неуязвимость, но и не даёт полный удар.
     */
    public function testPartialBaseProtectionStaysPartial(): void
    {
        $coef = EffectResultFactory::stateCoefficient(
            ['base_idle' => 0.06, 'biome_idle' => 0.5, 'biome_active' => 0.9],
            ['on_base' => true, 'is_gathering' => true, 'is_exploring' => false]
        );

        $this->assertSame(0.06, $coef);
    }
}
