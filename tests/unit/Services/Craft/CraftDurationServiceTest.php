<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Models\GameSettingsModel;
use App\Services\Craft\CraftDurationBreakdown;
use App\Services\Craft\CraftDurationService;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-158 — единая формула длительности крафта и её разбивка.
 *
 * Главная страховка теста: активация из очереди и старт крафта считают время ОДНИМ
 * сервисом. До этого копия формулы в GenericCraftCompletionHandler не применяла ни
 * одного множителя, и вторая вещь в очереди делалась дольше первой.
 *
 * @internal
 */
final class CraftDurationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /**
     * Подменяем только четыре внешних чтения (сервисы-коллабораторы объявлены final);
     * сборка разбивки — настоящая.
     *
     * @param list<array{label:string, mult:float}> $buildingFactors
     * @param array<string,float|int> $settings
     */
    private function service(
        array $buildingFactors = [],
        float $foodMult = 1.0,
        float $specMult = 1.0,
        float $factionMult = 1.0,
        array $settings = []
    ): CraftDurationService {
        $settingsModel = new class ($settings) extends GameSettingsModel {
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
                    'value_type'  => 'int',
                    'value_int'   => (int) $this->values[$key],
                ];
            }
        };

        return new class (
            new GameSettingsService($settingsModel),
            $buildingFactors,
            $foodMult,
            $specMult,
            $factionMult
        ) extends CraftDurationService {
            /** @param list<array{label:string, mult:float}> $buildingFactors */
            public function __construct(
                GameSettingsService $settings,
                private array $buildingFactors,
                private float $foodMult,
                private float $specMult,
                private float $factionMult
            ) {
                parent::__construct($settings);
            }

            protected function buildingFactors(int $charId, ?string $extraBuilding): array
            {
                return $this->buildingFactors;
            }

            protected function foodMultiplier(mixed $wellFedUntil): float
            {
                return $this->foodMult;
            }

            protected function specMultiplier(?string $branch, array $recipe, int $level): float
            {
                return $this->specMult;
            }

            protected function factionMultiplier(int $charId): float
            {
                return $this->factionMult;
            }
        };
    }

    /** @return array<string,int> */
    private function character(int $exp = 0, int $agi = 0, int $int = 0): array
    {
        return ['id' => 1, 'experience' => $exp, 'agility' => $agi, 'intellect' => $int, 'level' => 1];
    }

    /** @return array<string,int> */
    private function task(int $min, int $max): array
    {
        return ['min_duration' => $min, 'max_duration' => $max];
    }

    public function testNoviceGetsMaxDurationAndVeteranGetsMin(): void
    {
        $svc = $this->service();

        $this->assertSame(60, $svc->minutesForOne($this->character(), $this->task(20, 60)));
        $this->assertSame(20, $svc->minutesForOne($this->character(1000, 1000, 1000), $this->task(20, 60)));
    }

    public function testMultipliersStackMultiplicatively(): void
    {
        $svc = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90
        );

        // 100 × 0.55 × 0.90 × 0.80 × 0.90 = 35.64 → 36
        $this->assertSame(36, $svc->minutesForOne($this->character(), $this->task(100, 100)));
    }

    /** Даже полный стек не уводит длительность в ноль. */
    public function testDurationNeverDropsBelowOneMinute(): void
    {
        $svc = $this->service([['label' => 'Мастерская L10', 'mult' => 0.01]], 0.01, 0.01, 0.01);

        $this->assertSame(1, $svc->minutesForOne($this->character(), $this->task(1, 1)));
    }

    /** ADR-026: override хранит ЧАСЫ и делает время фиксированным. */
    public function testHoursOverrideReplacesTaskRange(): void
    {
        $svc = $this->service(settings: ['tier3.weapons.gauss_pistol.craft_duration_hours' => 2]);

        $minutes = $svc->minutesForOne(
            $this->character(1000, 1000, 1000),
            $this->task(10, 20),
            ['duration_override_setting_key' => 'tier3.weapons.gauss_pistol.craft_duration_hours']
        );

        $this->assertSame(120, $minutes);
    }

    public function testBreakdownListsOnlyActiveFactors(): void
    {
        $breakdown = $this->service([['label' => 'Мастерская L3', 'mult' => 0.75]], 0.90)
            ->forOne($this->character(), $this->task(100, 100));

        $this->assertTrue($breakdown->hasBonuses());
        $this->assertSame(100, $breakdown->baseMinutes);
        $this->assertSame(68, $breakdown->minutes);
        $this->assertSame(32, $breakdown->savedPercent());
        $this->assertCount(2, $breakdown->factors);
        $this->assertSame('Мастерская L3', $breakdown->factors[0]['label']);
        $this->assertSame('Сытость', $breakdown->factors[1]['label']);
    }

    public function testNoBonusesMeansNoBreakdown(): void
    {
        $breakdown = $this->service()->forOne($this->character(), $this->task(30, 30));

        $this->assertFalse($breakdown->hasBonuses());
        $this->assertSame(0, $breakdown->savedPercent());
        $this->assertSame([], $breakdown->factors);
    }

    /** Строка правды без бонусов — просто время, без «−0%» и пустого перечисления. */
    public function testTruthLineStaysQuietWithoutBonuses(): void
    {
        $line = $this->service()->forOne($this->character(), $this->task(30, 30))->truthLine();

        $this->assertSame('⏱ 30 мин', $line);
        $this->assertStringNotContainsString('база', $line);
    }

    public function testTruthLineNamesBaseAndSources(): void
    {
        $line = $this->service([['label' => 'Мастерская L10', 'mult' => 0.55]], 0.90)
            ->forOne($this->character(), $this->task(100, 100))
            ->truthLine();

        // 100 × 0.55 × 0.90 = 49.5 → 50 мин, то есть ровно −50% от базы.
        $this->assertStringContainsString('база 1 ч 40 мин', $line);
        $this->assertStringContainsString('−50%', $line);
        $this->assertStringContainsString('Мастерская L10', $line);
        $this->assertStringContainsString('Сытость', $line);
    }

    /** Время линейно по количеству — в строке должно быть время всей партии. */
    public function testTruthLineScalesWithQuantity(): void
    {
        $line = $this->service([['label' => 'Мастерская L3', 'mult' => 0.75]])
            ->forOne($this->character(), $this->task(60, 60))
            ->truthLine(3);

        $this->assertStringContainsString('2 ч 15 мин', $line);
        $this->assertStringContainsString('база 3 ч', $line);
    }

    /** Разметка легаси-Markdown: звёздочки и подчёркивания обязаны быть парными. */
    public function testTruthLineMarkdownIsBalanced(): void
    {
        $line = $this->service([['label' => 'Мастерская L10', 'mult' => 0.55]])
            ->forOne($this->character(), $this->task(100, 100))
            ->truthLine();

        $this->assertSame(0, substr_count($line, '*') % 2);
        $this->assertSame(0, substr_count($line, '_') % 2);
    }

    public function testHumanizeFormatsHoursAndMinutes(): void
    {
        $this->assertSame('45 мин', CraftDurationBreakdown::humanize(45));
        $this->assertSame('1 ч', CraftDurationBreakdown::humanize(60));
        $this->assertSame('2 ч 5 мин', CraftDurationBreakdown::humanize(125));
    }

    // ─── ADR-159: пол длительности крафта ────────────────────────────────────

    /** Полный стек множителей (×0.356) при поле 10 обязан упереться в 10, а не в 5. */
    public function testFloorStopsStackFromBreakingTimeGate(): void
    {
        $svc = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 10]
        );

        // 15 × 0.55 × 0.90 × 0.80 × 0.90 = 5.35 → без пола было бы 5 минут.
        $breakdown = $svc->forOne($this->character(), $this->task(15, 15));

        $this->assertSame(10, $breakdown->minutes);
        $this->assertSame(10, $breakdown->flooredAt);
    }

    /**
     * Пол — ограничитель СЖАТИЯ, а не минимальное время: короткий по замыслу рецепт
     * (база 3 мин) не удлиняется до 10, иначе это скрытый нерф раннего крафта.
     */
    public function testFloorNeverLengthensShortRecipe(): void
    {
        $stacked = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 10]
        );
        $this->assertSame(3, $stacked->minutesForOne($this->character(), $this->task(3, 3)));

        // И без единого бонуса база тоже остаётся собой.
        $plain = $this->service(settings: ['craft.min_duration_min' => 10]);
        $this->assertSame(3, $plain->minutesForOne($this->character(), $this->task(3, 3)));
        $this->assertSame(5, $plain->minutesForOne($this->character(), $this->task(5, 5)));
    }

    /** 0 = пол выключен: прежнее поведение, единственный ограничитель — 1 минута. */
    public function testFloorDisabledByZero(): void
    {
        $svc = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 0]
        );

        $breakdown = $svc->forOne($this->character(), $this->task(15, 15));

        $this->assertSame(5, $breakdown->minutes);
        $this->assertNull($breakdown->flooredAt);
    }

    /** Долгий крафт пол не трогает — сжатие остаётся полным. */
    public function testFloorLeavesLongCraftUntouched(): void
    {
        $breakdown = $this->service([['label' => 'Мастерская L10', 'mult' => 0.55]], settings: ['craft.min_duration_min' => 10])
            ->forOne($this->character(), $this->task(600, 600));

        $this->assertSame(330, $breakdown->minutes);
        $this->assertNull($breakdown->flooredAt);
    }

    /** Срабатывание пола обязано быть сказано вслух, а не выглядеть как поломка бонусов. */
    public function testTruthLineNamesTheFloorWhenItBites(): void
    {
        $line = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 10]
        )->forOne($this->character(), $this->task(15, 15))->truthLine();

        $this->assertStringContainsString('дальше не ускорить', $line);
        $this->assertStringContainsString('минимум 10 мин', $line);
        $this->assertSame(0, substr_count($line, '*') % 2);
        $this->assertSame(0, substr_count($line, '_') % 2);
    }

    /**
     * Пограничный случай: пол съел ВСЁ ускорение (база 10 = пол 10). Бонусов «нет»,
     * но игрок обязан узнать, почему его стек ничего не дал.
     */
    public function testFloorNoteShownEvenWhenWholeSpeedupIsEaten(): void
    {
        $breakdown = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 10]
        )->forOne($this->character(), $this->task(10, 10));

        $this->assertSame(10, $breakdown->minutes);
        $this->assertFalse($breakdown->hasBonuses(), 'выигрыша по времени нет — «−0%» писать нельзя');

        $line = $breakdown->truthLine();
        $this->assertStringContainsString('дальше не ускорить', $line);
        $this->assertStringNotContainsString('−0%', $line);
        $this->assertStringNotContainsString('ускоряют', $line);
    }

    /**
     * Рецепт короче настроенного пола: эффективный предел — его собственная база,
     * поэтому число печатать нельзя (оно гуляло бы от рецепта к рецепту и читалось
     * как глобальное правило игры). Поймано Tier-3 на седативном (база 7 при поле 10).
     */
    public function testFloorNoteAvoidsPerRecipeNumberWhenRecipeIsShorterThanFloor(): void
    {
        $line = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            settings: ['craft.min_duration_min' => 10]
        )->forOne($this->character(), $this->task(7, 7))->truthLine();

        $this->assertStringContainsString('и так самый быстрый', $line);
        $this->assertStringNotContainsString('минимум 7 мин', $line);
        $this->assertStringNotContainsString('минимум 10 мин', $line);
    }

    /** Пол применяется на штуку: строка партии считает минимум для каждой единицы. */
    public function testFloorAppliesPerItemInBatch(): void
    {
        $breakdown = $this->service(
            [['label' => 'Мастерская L10', 'mult' => 0.55]],
            0.90,
            0.80,
            0.90,
            ['craft.min_duration_min' => 10]
        )->forOne($this->character(), $this->task(15, 15));

        $this->assertStringContainsString('30 мин', $breakdown->truthLine(3));
        $this->assertStringContainsString('на штуку', $breakdown->truthLine(3));
    }
}
