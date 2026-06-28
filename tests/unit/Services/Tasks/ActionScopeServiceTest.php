<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tasks;

use App\Services\Tasks\ActionScopeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-143 — легенда «где / в фоне» для крафта и стройки.
 *
 * Сервис чистый (без DB), поэтому покрываем все ветки нормализации флага,
 * обе оси (позиция + занятость) и markdown-инвариант (парные `_`, нет `*`).
 *
 * @internal
 */
final class ActionScopeServiceTest extends CIUnitTestCase
{
    private ActionScopeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ActionScopeService();
    }

    /**
     * @dataProvider backgroundFlagProvider
     */
    public function testIsBackgroundNormalizesAnyDbShape(mixed $flag, bool $expected): void
    {
        $this->assertSame($expected, $this->svc->isBackground($flag));
    }

    /** @return list<array{0:mixed,1:bool}> */
    public static function backgroundFlagProvider(): array
    {
        return [
            [1, true],
            [0, false],
            ['1', true],
            ['0', false],
            [true, true],
            [false, false],
            [null, false],
            ['', false],
            ['yes', false],
        ];
    }

    public function testPositionBadgeCraftIsAnywhere(): void
    {
        $this->assertSame('🌍 Где угодно', $this->svc->positionBadge(ActionScopeService::KIND_CRAFT));
    }

    public function testPositionBadgeBuildIsBaseOnly(): void
    {
        $this->assertSame('🏠 Только на базе', $this->svc->positionBadge(ActionScopeService::KIND_BUILD));
    }

    public function testOccupancyBadgeBackgroundVsBlocking(): void
    {
        $this->assertSame('⏳ Идёт в фоне', $this->svc->occupancyBadge(true));
        $this->assertSame('🔒 Займёт целиком', $this->svc->occupancyBadge(false));
    }

    public function testScopeLineCombinesBothAxes(): void
    {
        $this->assertSame(
            '🌍 Где угодно · ⏳ Идёт в фоне',
            $this->svc->scopeLine(ActionScopeService::KIND_CRAFT, true),
        );
        $this->assertSame(
            '🏠 Только на базе · ⏳ Идёт в фоне',
            $this->svc->scopeLine(ActionScopeService::KIND_BUILD, true),
        );
        $this->assertSame(
            '🌍 Где угодно · 🔒 Займёт целиком',
            $this->svc->scopeLine(ActionScopeService::KIND_CRAFT, false),
        );
    }

    public function testReassuranceBranchesAreDistinctAndTruthful(): void
    {
        $craftBg    = $this->svc->reassurance(ActionScopeService::KIND_CRAFT, true);
        $craftBlock = $this->svc->reassurance(ActionScopeService::KIND_CRAFT, false);
        $build      = $this->svc->reassurance(ActionScopeService::KIND_BUILD, true);

        // Фоновый крафт обещает свободу действий.
        $this->assertStringContainsString('добывать', $craftBg);
        $this->assertStringContainsString('Поход', $craftBg);
        // Блокирующий крафт честно запрещает.
        $this->assertStringContainsString('нельзя', $craftBlock);
        // Стройка — про «уходить, идёт сама».
        $this->assertStringContainsString('стройка идёт сама', $build);

        $this->assertNotSame($craftBg, $craftBlock);
    }

    public function testStartedBlockHasScopeLineAndReassurance(): void
    {
        $block = $this->svc->startedBlock(ActionScopeService::KIND_CRAFT, true);
        $this->assertStringContainsString('🌍 Где угодно · ⏳ Идёт в фоне', $block);
        $this->assertStringContainsString('добывать', $block);
        $this->assertStringContainsString("\n", $block);
    }

    public function testLegendDiffersByKind(): void
    {
        $craft = $this->svc->legend(ActionScopeService::KIND_CRAFT);
        $build = $this->svc->legend(ActionScopeService::KIND_BUILD);

        $this->assertStringContainsString('где угодно', $craft);
        $this->assertStringContainsString('только стоя на своей базе', $build);
        $this->assertNotSame($craft, $build);
    }

    /**
     * Markdown-инвариант: все player-facing строки имеют парные `_` и не содержат
     * `*` (Telegram legacy Markdown иначе ломает рендер — урок S5b/Sell).
     *
     * @dataProvider renderedStringsProvider
     */
    public function testMarkdownIsBalancedAndStarFree(string $rendered): void
    {
        $this->assertSame(
            0,
            substr_count($rendered, '*'),
            "Строка не должна содержать '*': {$rendered}",
        );
        $this->assertSame(
            0,
            substr_count($rendered, '_') % 2,
            "Непарные '_' в строке: {$rendered}",
        );
    }

    /** @return list<array{0:string}> */
    public static function renderedStringsProvider(): array
    {
        $svc  = new ActionScopeService();
        $out  = [];
        foreach ([ActionScopeService::KIND_CRAFT, ActionScopeService::KIND_BUILD] as $kind) {
            foreach ([true, false] as $bg) {
                $out[] = [$svc->scopeLine($kind, $bg)];
                $out[] = [$svc->reassurance($kind, $bg)];
                $out[] = [$svc->startedBlock($kind, $bg)];
            }
            $out[] = [$svc->legend($kind)];
        }
        return $out;
    }
}
