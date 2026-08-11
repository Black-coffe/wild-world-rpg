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

    /**
     * Anti-drift: ключи, по которым T3/готовка-select-экраны берут представительную
     * занятость через isRecipeBackground(). Если ключ переименуют/опечатают —
     * isRecipeBackground вернёт default и предупреждение может стать неверным.
     * Гард ловит рассинхрон select ↔ Config\CraftRecipes (без DB).
     */
    public function testRepresentativeRecipeKeysResolve(): void
    {
        $keys = [
            'GaussPistol', 'TacticalArmorSuit', 'SyntheticMedicine', 'DiamondPickaxe',
            'BunkerRifle', 'BunkerPlateArmor', 'MushroomSoup',
        ];
        $cfg = config('CraftRecipes');
        foreach ($keys as $key) {
            $recipe = $cfg->get($key);
            $this->assertIsArray($recipe, "Рецепт '{$key}' должен существовать в CraftRecipes (select берёт по нему занятость)");
            $this->assertArrayHasKey('task_name', $recipe, "У рецепта '{$key}' должен быть task_name");
        }
    }

    public function testOccupancyWarningBranchesAreDistinct(): void
    {
        $blocking = $this->svc->occupancyWarning(false);
        $bg       = $this->svc->occupancyWarning(true);

        $this->assertStringContainsString('🔒', $blocking);
        $this->assertStringContainsString('нельзя', $blocking);
        $this->assertStringContainsString('⏳', $bg);
        $this->assertStringContainsString('добывать', $bg);
        $this->assertNotSame($blocking, $bg);
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
     * ADR-167 — экран отказа объясняет ПРАВИЛО, а не только факт: игрок должен
     * прочитать, почему нельзя, и куда идти дальше. Иначе блокировка читается
     * как новый баг (ровно то, с чего началась жалоба про асимметрию).
     */
    public function testExclusiveBlockTextNamesBothSidesAndTheRule(): void
    {
        $text = $this->svc->exclusiveBlockText('Добыча ресурсов', 42, 'Готовка: Грибная похлёбка');

        $this->assertStringContainsString('Добыча ресурсов', $text, 'Должно быть видно, чем игрок занят');
        $this->assertStringContainsString('Готовка: Грибная похлёбка', $text, 'И что он пытался начать');
        $this->assertStringContainsString('42 мин.', $text, 'И сколько ждать');
        $this->assertStringContainsString('/tasks', $text, 'И куда идти отменять');
        $this->assertStringContainsString('🔒', $text);
    }

    /** Без имени стартующей задачи текст не должен ломаться или сыпать пустой строкой. */
    public function testExclusiveBlockTextWorksWithoutAttemptedName(): void
    {
        $text = $this->svc->exclusiveBlockText('Ремонт инструмента', 5);

        $this->assertStringContainsString('Ремонт инструмента', $text);
        $this->assertStringNotContainsString('Хочешь начать:', $text);
    }

    /**
     * Имена приходят из БД (`tasks.name_rus`) — символы разметки обязаны
     * вырезаться. Один непарный `_` в legacy Markdown = ok=false и игрок не
     * получает вообще ничего (урок feedback_legacy_markdown_no_backslash_escape).
     */
    public function testExclusiveBlockTextStripsMarkdownFromDbNames(): void
    {
        $text = $this->svc->exclusiveBlockText('Крафт_*hostile* [name]', 10, 'Ещё_один*');

        $this->assertSame(0, substr_count($text, '*'), "В тексте не должно остаться '*': {$text}");
        $this->assertSame(0, substr_count($text, '_') % 2, "Непарные '_': {$text}");
        $this->assertStringContainsString('Крафтhostile', $text);
    }

    /**
     * «0 мин.» рядом с отказом выглядит как поломка («ждать нечего, а не пускает»),
     * поэтому нулевой и отрицательный остаток говорим словами.
     *
     * @dataProvider humanMinutesProvider
     */
    public function testHumanMinutesReadsLikeSpeech(int $minutes, string $expected): void
    {
        $this->assertSame($expected, $this->svc->humanMinutes($minutes));
    }

    /** @return list<array{0:int,1:string}> */
    public static function humanMinutesProvider(): array
    {
        return [
            [-5,   'меньше минуты'],
            [0,    'меньше минуты'],
            [1,    '1 мин.'],
            [59,   '59 мин.'],
            [60,   '1 ч'],
            [125,  '2 ч 5 мин.'],
            [1440, '1 дн.'],
            [1620, '1 дн. 3 ч'],
        ];
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
        $out[] = [$svc->occupancyWarning(true)];
        $out[] = [$svc->occupancyWarning(false)];
        // ADR-167: экран отказа — тоже player-facing строка, тот же инвариант.
        $out[] = [$svc->exclusiveBlockText('Добыча ресурсов', 42, 'Готовка: Грибная похлёбка')];
        $out[] = [$svc->exclusiveBlockText('Ремонт инструмента', 0)];
        return $out;
    }
}
