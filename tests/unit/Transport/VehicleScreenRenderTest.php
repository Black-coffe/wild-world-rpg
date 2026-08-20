<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\Vehicle\VehicleAction;
use App\Services\Player\VehicleScreenRenderer;
use App\Services\World\VehicleEffectsService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * transport-10 (ADR-174, docs/specs/transport-system/) — `VehicleScreenRenderer` — чистая
 * render-функция экрана «🚚 Мой транспорт», без БД и Telegram. Ядро приёмки story.
 *
 * @internal
 */
final class VehicleScreenRenderTest extends CIUnitTestCase
{
    /**
     * @return array{faction_id:int, faction_name:string, faction_icon:string, vehicle_name:string, vehicle_icon:string, required_level:int, unlocked:bool}
     */
    private function showcaseCard(int $factionId, string $factionName, string $vehicleName, int $level, bool $unlocked): array
    {
        return [
            'faction_id'     => $factionId,
            'faction_name'   => $factionName,
            'faction_icon'   => '🛡️',
            'vehicle_name'   => $vehicleName,
            'vehicle_icon'   => '🚗',
            'required_level' => $level,
            'unlocked'       => $unlocked,
        ];
    }

    /** @return list<array{faction_id:int, faction_name:string, faction_icon:string, vehicle_name:string, vehicle_icon:string, required_level:int, unlocked:bool}> */
    private function fullShowcase(bool $unlocked = false): array
    {
        return [
            $this->showcaseCard(1, 'Милитари', 'Снегоход', 14, $unlocked),
            $this->showcaseCard(2, 'Партизаны', 'Горный велосипед', 12, $unlocked),
            $this->showcaseCard(3, 'Инженеры', 'Автономный дрон', 16, $unlocked),
            $this->showcaseCard(4, 'Фермеры', 'Тягловая повозка', 14, $unlocked),
        ];
    }

    public function testEmptyStateShowsNoActiveVehicleAndFullShowcase(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active'          => null,
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 3,
        ]);

        $this->assertStringContainsString('нет', mb_strtolower($out['text']));
        $this->assertStringContainsString('🔒', $out['text']);
        $this->assertStringContainsString('Инженеры', $out['text']);
        $this->assertStringContainsString('Фракция выбирается на 10 уровне, один раз и навсегда', $out['text']);
    }

    public function testWearIsShownInCellsNotPercent(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 40, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 0,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $this->assertStringContainsString('~40 клеток', $out['text']);
        $this->assertStringNotContainsString('%', explode("\n", $out['text'])[2] ?? '');
    }

    public function testBrokenVehicleShowsRepairPromptAtZeroCharges(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 0, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 12,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $this->assertStringContainsString('Разбита', $out['text']);
        $this->assertStringContainsString('ремонт', mb_strtolower($out['text']));
    }

    public function testWarningAppearsBeforeCharacterHitsZero(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Снегоход', 'icon' => '🛻',
                'cells_left' => 20, 'charges_full' => 400, 'wear_per_cell' => 2,
                'savings_minutes' => 0,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 14,
        ]);

        $this->assertStringContainsString('⚠️', $out['text']);
    }

    public function testPaybackLineShowsAccumulatedSavings(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 200, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 47,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $this->assertStringContainsString('сэкономила тебе ~47 мин', $out['text']);
    }

    public function testPaybackLineAbsentWhenNoSavingsYet(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 300, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 0,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $this->assertStringNotContainsString('мин.', $out['text']);
        $this->assertStringNotContainsString('0 мин', $out['text']);
    }

    public function testShowcaseVisibleForFactionlessCharacterWithNoVehicle(): void
    {
        // id 5 = «не выбрано», уровень ниже всех фракционных требований.
        $out = VehicleScreenRenderer::renderScreen([
            'active'          => null,
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 11,
        ]);

        foreach (['Милитари', 'Партизаны', 'Инженеры', 'Фермеры'] as $factionName) {
            $this->assertStringContainsString($factionName, $out['text']);
        }
        $this->assertStringContainsString('у тебя 11', $out['text']);
    }

    public function testUnlockedShowcaseCardHasNoLockGlyph(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active'          => null,
            'garage'          => [],
            'showcase'        => [$this->showcaseCard(3, 'Инженеры', 'Автономный дрон', 16, true)],
            'character_level' => 20,
        ]);

        $this->assertStringContainsString('✅', $out['text']);
    }

    public function testGarageButtonsUseVehicleActivateCallback(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active'          => null,
            'garage'          => [
                ['log_id' => 42, 'name' => 'Лёгкая повозка', 'icon' => '🛒'],
            ],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $flat = array_merge(...$out['buttons']);
        $callbacks = array_column($flat, 'callback_data');
        $this->assertContains('vehicleActivate_42', $callbacks);
    }

    public function testDeactivateButtonPresentOnlyWhenActive(): void
    {
        $withActive = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 10, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 0,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);
        $flatWith = array_merge(...$withActive['buttons']);
        $this->assertContains('vehicleDeactivate', array_column($flatWith, 'callback_data'));

        $withoutActive = VehicleScreenRenderer::renderScreen([
            'active'          => null,
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);
        $flatWithout = array_merge(...$withoutActive['buttons']);
        $this->assertNotContains('vehicleDeactivate', array_column($flatWithout, 'callback_data'));
    }

    public function testNoRowHasExactlyOneButton(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 10, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 5,
            ],
            'garage' => [
                ['log_id' => 1, 'name' => 'А', 'icon' => '🛒'],
                ['log_id' => 2, 'name' => 'Б', 'icon' => '🚲'],
                ['log_id' => 3, 'name' => 'В', 'icon' => '🛻'],
            ],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        foreach ($out['buttons'] as $row) {
            $this->assertGreaterThanOrEqual(2, count($row), 'ряд не должен состоять из одной кнопки');
            $this->assertLessThanOrEqual(3, count($row));
        }
    }

    /**
     * 🔴 Caption ≤ 1024 символов на самом длинном состоянии: активная машина + пять
     * карточек гаража + полная витрина (feedback `feedback_caption_length_needs_a_test_not_a_note`).
     */
    public function testCaptionFitsWithinTelegramLimitOnWorstCaseState(): void
    {
        $garage = [];
        for ($i = 1; $i <= 5; $i++) {
            $garage[] = ['log_id' => $i, 'name' => 'Тягловая повозка', 'icon' => '🐎'];
        }

        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Автономный дрон', 'icon' => '🛸',
                'cells_left' => 12, 'charges_full' => 350, 'wear_per_cell' => 1,
                'savings_minutes' => 999,
            ],
            'garage'          => $garage,
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 9,
        ]);

        $this->assertLessThanOrEqual(1024, mb_strlen($out['text']));
    }

    public function testShowcaseScreenRendersAllFourCardsStandalone(): void
    {
        $out = VehicleScreenRenderer::renderShowcase([
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 5,
        ]);

        foreach (['Милитари', 'Партизаны', 'Инженеры', 'Фермеры'] as $factionName) {
            $this->assertStringContainsString($factionName, $out['text']);
        }
        $this->assertLessThanOrEqual(1024, mb_strlen($out['text']));
    }

    public function testAsterisksArePairedInRenderedText(): void
    {
        $out = VehicleScreenRenderer::renderScreen([
            'active' => [
                'name' => 'Лёгкая повозка', 'icon' => '🛒',
                'cells_left' => 40, 'charges_full' => 300, 'wear_per_cell' => 1,
                'savings_minutes' => 3,
            ],
            'garage'          => [],
            'showcase'        => $this->fullShowcase(false),
            'character_level' => 6,
        ]);

        $this->assertSame(0, substr_count($out['text'], '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
    }

    /**
     * `VehicleAction::lockInfoText()` — чистая render-функция экрана «причина блокировки»
     * (callback `vehicleLockInfo_<key>`). Общая `LightCart` закрыта только уровнем, у неё
     * нет `required_faction` вовсе — экран не имеет права придумывать фракцию.
     *
     * @return array<string,mixed>
     */
    private function lightCartRecipe(): array
    {
        return [
            'item_name_rus'  => 'Лёгкая повозка',
            'required_level' => 6,
        ];
    }

    /** @return array<string,mixed> */
    private function factionRecipe(): array
    {
        return [
            'item_name_rus'    => 'Снегоход',
            'required_level'   => 14,
            'required_faction' => 1, // Милитари
        ];
    }

    public function testLockInfoLevelOnlyNamesLevelNotFaction(): void
    {
        $text = VehicleAction::lockInfoText($this->lightCartRecipe(), 'LightCart', 3, 0);

        $this->assertStringContainsString('уровень', mb_strtolower($text));
        $this->assertStringContainsString('6', $text);
        $this->assertStringContainsString('3', $text);
        $this->assertStringNotContainsString('фракц', mb_strtolower($text));
        $this->assertStringNotContainsString('?', $text);
    }

    public function testLockInfoFactionOnlyNamesFactionNotLevel(): void
    {
        // Уровень уже набран (20 ≥ 14), но выбрана чужая фракция.
        $text = VehicleAction::lockInfoText($this->factionRecipe(), 'Snowmobile', 20, 2);

        $this->assertStringContainsString('Милитари', $text);
        $this->assertStringNotContainsString('Нужен', $text);
        $this->assertStringNotContainsString('?', $text);
    }

    public function testLockInfoBothLevelAndFactionAreNamed(): void
    {
        $text = VehicleAction::lockInfoText($this->factionRecipe(), 'Snowmobile', 5, 2);

        $this->assertStringContainsString('Милитари', $text);
        $this->assertStringContainsString('уровень', mb_strtolower($text));
        $this->assertStringContainsString('14', $text);
        $this->assertStringContainsString('5', $text);
        $this->assertStringNotContainsString('?', $text);
    }

    public function testLockInfoUnknownRecipeKeyIsSafe(): void
    {
        $text = VehicleAction::lockInfoText(null, 'NoSuchRecipe', 5, 0);

        $this->assertStringNotContainsString('?', $text);
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарная * роняет Legacy Markdown отправку молча');
        $this->assertLessThanOrEqual(1024, mb_strlen($text));
    }

    // ── Ревью-находка 2026-08-20: `VehicleAction::savingsMinutes()` ──────────
    // Приватный static-метод (тестируется рефлексией без сборки VehicleAction —
    // конструктор требует CallbackQuery + модели персонажа).

    private function invokeSavingsMinutes(VehicleEffectsService $effects, ?string $key, int $charges, int $chargesFull, int $wearPerCell, int $minutesPerCell): int
    {
        $method = new ReflectionMethod(VehicleAction::class, 'savingsMinutes');
        $method->setAccessible(true);

        return $method->invoke(null, $effects, $key, $charges, $chargesFull, $wearPerCell, $minutesPerCell);
    }

    /**
     * 🔴 Ядро приёмки: свежескрафченная машина (`charges === chargesFull`, единый источник —
     * GameSettings `world.vehicle.<key>.charges_full`) не показывает выдуманных клеток
     * окупаемости. Раньше клэмп остатка и `chargesFull` текста читались из РАЗНЫХ источников
     * (каталог vs GameSettings) — свежая машина 120/300 давала мнимые 180 клеток пройденного
     * пути. Здесь оба параметра — уже согласованный вывод одного источника (charges_full).
     */
    public function testSavingsMinutesFreshVehicleShowsZeroNotImaginaryCells(): void
    {
        $effects = new VehicleEffectsService(null, [
            'world.vehicle.enabled'                     => true,
            'world.vehicle.cart.cells_per_tick_unexplored' => 5,
        ]);

        $savings = $this->invokeSavingsMinutes($effects, 'cart', 300, 300, 1, 1);

        $this->assertSame(0, $savings, 'полный заряд = ноль пройденных клеток = ноль окупаемости');
    }

    /** Изношенная машина (реально пройдены клетки) — окупаемость положительная. */
    public function testSavingsMinutesWornVehicleShowsPositiveSavings(): void
    {
        $effects = new VehicleEffectsService(null, [
            'world.vehicle.enabled'                        => true,
            'world.march.cells_per_tick'                    => 3,
            'world.vehicle.cart.cells_per_tick_unexplored'  => 4,
        ]);

        $savings = $this->invokeSavingsMinutes($effects, 'cart', 280, 300, 1, 1);

        $this->assertSame(2, $savings, 'ceil(20/3) − ceil(20/4) = 7 − 5 = 2 минуты');
    }

    public function testSavingsMinutesZeroWhenVehicleEffectsDisabled(): void
    {
        $effects = new VehicleEffectsService(null, ['world.vehicle.enabled' => false]);

        $savings = $this->invokeSavingsMinutes($effects, 'cart', 280, 300, 1, 1);

        $this->assertSame(0, $savings, 'килсвитч world.vehicle.enabled=false — окупаемость всегда 0');
    }
}
