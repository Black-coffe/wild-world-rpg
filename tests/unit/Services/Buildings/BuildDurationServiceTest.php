<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Buildings;

use App\Models\GameSettingsModel;
use App\Services\Buildings\BuildDurationService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-160 — единственная точка расчёта длительности стройки.
 *
 * До этого формула жила двумя копиями: реальная стройка (`GenericBuildingAction`) и
 * обещание «Строительство займёт ~N минут» (`GenericBuildingInfoAction`). Копии УЖЕ
 * разъехались — экран урезал статы до целых (`(int) 0.16 → 0`), стройка считала по
 * дробным. Тест держит и поведение сервиса, и отсутствие копий формулы в обоих экранах.
 *
 * @internal
 */
final class BuildDurationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /** @param array<string,float|int> $settings */
    private function service(array $settings = []): BuildDurationService
    {
        $model = new class ($settings) extends GameSettingsModel {
            /** @param array<string,float|int> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return [
                    'setting_key' => $key,
                    'value_type'  => 'float',
                    'value_float' => (float) $this->values[$key],
                ];
            }
        };

        return new BuildDurationService(new GameSettingsService($model));
    }

    /** @return array<string,int|float> */
    private function character(float $exp = 0.0, float $agi = 0.0, float $int = 0.0): array
    {
        return ['id' => 1, 'experience' => $exp, 'agility' => $agi, 'intellect' => $int];
    }

    /** @return array<string,int> */
    private function task(int $min, int $max): array
    {
        return ['min_duration' => $min, 'max_duration' => $max];
    }

    public function testNoviceGetsMaxAndVeteranGetsMin(): void
    {
        $svc = $this->service();

        $this->assertSame(70, $svc->minutes($this->character(), $this->task(46, 70)));
        $this->assertSame(46, $svc->minutes($this->character(10000, 10000, 10000), $this->task(46, 70)));
    }

    /**
     * Дробная часть статов участвует в расчёте (экран информации раньше урезал её до
     * целых: `(int) 0.16 → 0`).
     *
     * ⚠️ Величина эффекта мала: потерянная дробь даёт сдвиг счёта < 1 при потолке 2000,
     * то есть < 0.06 минуты на 120-минутном диапазоне — расхождение копий было реальным,
     * но по масштабу это граница округления, а не видимая игроку ложь. Чтобы разница
     * вообще проявилась в целых минутах, нужен широкий диапазон: при `0..2000`
     * длительность = `2000 − счёт`, и половина единицы счёта уже флипает округление.
     */
    public function testFractionalStatsParticipateInScore(): void
    {
        $svc = $this->service();

        $withFraction = $svc->minutes($this->character(0.0, 0.0, 1.5), $this->task(0, 2000)); // счёт 0.6
        $integerOnly  = $svc->minutes($this->character(0.0, 0.0, 1.0), $this->task(0, 2000)); // счёт 0.4

        $this->assertSame(1999, $withFraction);
        $this->assertSame(2000, $integerOnly);
    }

    /** Задачи нет → null: выдумывать число нельзя, это стало бы новым источником правды. */
    public function testMissingTaskRowYieldsNull(): void
    {
        $this->assertNull($this->service()->minutes($this->character(), null));
    }

    /** Задача без верхней границы → нижняя (прежнее поведение экрана информации). */
    public function testNoUpperBoundFallsBackToMin(): void
    {
        $svc = $this->service();

        $this->assertSame(1440, $svc->minutes($this->character(), $this->task(1440, 0)));
        $this->assertNull($svc->minutes($this->character(), $this->task(0, 0)));
    }

    /**
     * Потолок счёта берётся из GameSettings и реально меняет результат.
     *
     * 🔴 `service('cache')->clean()` между замерами обязателен: GameSettingsService
     * кэширует найденные значения ГЛОБАЛЬНО (memory `feedback_gamesettings_cache_60s…`),
     * поэтому второй сервис иначе отравляет замер первого — на этом тест и упал сначала.
     */
    public function testScoreCapIsTunable(): void
    {
        $character = $this->character(2000.0); // счёт = 600

        $default        = $this->service();
        $defaultCap     = $default->scoreCap();
        $defaultMinutes = $default->minutes($character, $this->task(60, 180));

        service('cache')->clean();

        $lowered        = $this->service([BuildDurationService::SETTING_SCORE_CAP => 1000.0]);
        $loweredCap     = $lowered->scoreCap();
        $loweredMinutes = $lowered->minutes($character, $this->task(60, 180));

        $this->assertSame(2000.0, $defaultCap);
        $this->assertSame(1000.0, $loweredCap);
        $this->assertSame(144, $defaultMinutes, 'счёт 600 при потолке 2000 → 60 + 120×0.7');
        $this->assertSame(108, $loweredMinutes, 'счёт 600 при потолке 1000 → 60 + 120×0.4');
    }

    /** Мусорное/неположительное значение ключа не ломает расчёт — падаем на дефолт. */
    public function testBrokenScoreCapFallsBackToDefault(): void
    {
        $this->assertSame(2000.0, $this->service([BuildDurationService::SETTING_SCORE_CAP => 0])->scoreCap());
        $this->assertSame(2000.0, $this->service([BuildDurationService::SETTING_SCORE_CAP => -5])->scoreCap());
    }

    /**
     * Анти-дрейф: ни один из двух экранов стройки не считает время сам. Иначе копии
     * снова разъедутся — ровно то, что уже случилось до ADR-160.
     */
    public function testNeitherBuildScreenComputesDurationItself(): void
    {
        $files = [
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/GenericBuildingAction.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/GenericBuildingInfoAction.php',
        ];

        foreach ($files as $file) {
            $src = file_get_contents($file);
            $this->assertIsString($src);

            $this->assertStringContainsString('BuildDurationService', $src, basename($file) . ' обязан считать через сервис');

            // Признаки собственной копии формулы: потолок счёта и веса статов.
            foreach (['2000.0', '* 0.3', '* 0.4'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    basename($file) . ": найден фрагмент собственной формулы «{$needle}» — расчёт обязан жить только в BuildDurationService"
                );
            }
        }
    }
}
