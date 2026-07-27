<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\GameSettingsModel;
use App\Services\Economy\VendorDailyLimitService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-157 шаг 2 — суточный лимит выкупа у торговца.
 *
 * Закрывает остаточный кран: у 22 позиций из 39 ВСЕ входы рецепта покупаются у того
 * же торговца без лимита стока (худший случай — «Рыбные консервы»: входы ~5.6 золота,
 * выкуп ~374, то есть ×67 за круг).
 *
 * @internal
 */
final class VendorDailyLimitServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /**
     * @param array<string,float|bool> $overrides
     */
    private function service(float $alreadySold, array $overrides = []): VendorDailyLimitService
    {
        $settingsModel = new class ($overrides) extends GameSettingsModel {
            /** @param array<string,float|bool> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->overrides)) {
                    return null;
                }
                $value = $this->overrides[$key];

                return [
                    'setting_key' => $key,
                    'value_type'  => is_bool($value) ? 'bool' : 'float',
                    'value_bool'  => is_bool($value) ? ($value ? 1 : 0) : null,
                    'value_float' => is_bool($value) ? null : $value,
                ];
            }
        };

        return new class (new GameSettingsService($settingsModel), $alreadySold) extends VendorDailyLimitService {
            public function __construct(GameSettingsService $settings, private float $alreadySold)
            {
                parent::__construct($settings, null);
            }

            public function soldLast24h(int $characterId): float
            {
                return $this->alreadySold; // подменяем БД-чтение
            }
        };
    }

    public function testAllowsSaleWithinCap(): void
    {
        $svc = $this->service(10000.0);

        $this->assertTrue($svc->allows(1, 5000.0));
        $this->assertEqualsWithDelta(40000.0, $svc->remaining(1), 0.001);
    }

    public function testBlocksSaleThatWouldExceedCap(): void
    {
        $svc = $this->service(48000.0);

        $this->assertFalse($svc->allows(1, 5000.0));
        $this->assertTrue($svc->allows(1, 2000.0));
    }

    public function testBlocksEverythingWhenCapAlreadyReached(): void
    {
        $svc = $this->service(50000.0);

        $this->assertSame(0.0, $svc->remaining(1));
        $this->assertFalse($svc->allows(1, 1.0));
    }

    /** Killswitch снимает лимит полностью — путь отката без редеплоя. */
    public function testDisabledMeansNoLimit(): void
    {
        $svc = $this->service(10_000_000.0, [VendorDailyLimitService::KEY_ENABLED => false]);

        $this->assertSame(INF, $svc->remaining(1));
        $this->assertTrue($svc->allows(1, 999_999_999.0));
    }

    public function testCapIsTunable(): void
    {
        $svc = $this->service(0.0, [VendorDailyLimitService::KEY_CAP => 1234.0]);

        $this->assertEqualsWithDelta(1234.0, $svc->remaining(1), 0.001);
        $this->assertFalse($svc->allows(1, 1235.0));
    }

    /**
     * Регрессия остаточного крана: «Рыбные консервы» дают ~368 золота профита с
     * единицы при выкупе ~374. Лимит обязан упереть цикл в потолок за сутки.
     */
    public function testFishPreserveCycleHitsCapWithinOneDay(): void
    {
        $revenuePerUnit = 374.0;
        $sold           = 0.0;
        $units          = 0;

        while ($units < 100000) {
            $svc = $this->service($sold);
            if (! $svc->allows(1, $revenuePerUnit)) {
                break;
            }
            $sold += $revenuePerUnit;
            $units++;
        }

        $this->assertLessThan(150, $units, 'цикл не упёрся в суточный потолок');
        $this->assertLessThanOrEqual(50000.0, $sold);
    }
}
