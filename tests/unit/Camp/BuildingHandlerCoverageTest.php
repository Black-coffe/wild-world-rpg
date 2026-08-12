<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Buildings;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ экранов построек (CLAUDE.md §🎮 UX-DISCOVERABILITY).
 *
 * `BuildingHandlerAction::handle()` — ручной `switch` по `name_eng`. Добавить
 * здание в `Config\Buildings` и забыть case ничего не ломает на этапе сборки:
 * постройка спокойно строится, показывается на базе кнопкой — и отвечает
 * «Неизвестное строение» на тап.
 *
 * Так уже было дважды:
 *  - ADR-041 — оборонные постройки (WoodenWall/BarbedFence/WatchTower);
 *  - S5/ADR-142 — «Навес» (LeanTo), ПЕРВАЯ постройка новичка. Прожил в таком
 *    виде с 27.06 до 12.08, 21 владелец на проде, поймал игрок в Bugs-info,
 *    а не тесты.
 *
 * Класс бага повторился → гейт. Тест разбирает исходники (DB-независим,
 * Telegram-независим), поэтому работает в любой CI-полосе.
 *
 * @internal
 */
final class BuildingHandlerCoverageTest extends CIUnitTestCase
{
    private const HANDLER = 'app/Controllers/Telegram/Commands/Actions/Camp/BuildingHandlerAction.php';

    /**
     * Главный гейт: у каждой постройки из конфига есть case в switch.
     */
    public function testEveryConfiguredBuildingHasHandlerCase(): void
    {
        $configured = array_keys((new Buildings())->recipes);
        $cases      = $this->handlerCases();

        $missing = array_values(array_diff($configured, $cases));

        $this->assertSame([], $missing, sprintf(
            "Постройки без case в BuildingHandlerAction: %s.\n"
            . "Игрок построит их, увидит кнопку на базе — и получит «Неизвестное строение» на тап.\n"
            . 'Заведи case + экран-handler (образцы: LeanToHandler, DefensiveBuildingHandler).',
            implode(', ', $missing)
        ));
    }

    /**
     * Обратная сторона: case на постройку, которой нет в конфиге, — мёртвая ветка.
     * Обычно означает переименование ключа, при котором старый case забыли убрать.
     */
    public function testNoHandlerCaseForUnknownBuilding(): void
    {
        $configured = array_keys((new Buildings())->recipes);
        $orphanCases = array_values(array_diff($this->handlerCases(), $configured));

        $this->assertSame([], $orphanCases, sprintf(
            'case в BuildingHandlerAction без записи в Config\Buildings: %s.',
            implode(', ', $orphanCases)
        ));
    }

    /**
     * Ветка `default` обязана оставаться дружелюбной: с выходом на базу, а не
     * голым текстом-тупиком. Тупик — это то, из-за чего игроки идут в чат
     * за помощью (ADR-103, навигационная устойчивость).
     */
    public function testDefaultBranchOffersWayBack(): void
    {
        $src = $this->handlerSource();
        $tail = substr($src, strpos($src, 'default:') ?: 0);

        $this->assertStringContainsString(
            "'callback_data' => 'Base'",
            $tail,
            'Ветка default в BuildingHandlerAction обязана давать кнопку возврата на базу, а не оставлять игрока в тупике.'
        );
    }

    /**
     * @return list<string> Значения всех `case '...':` в switch хендлера.
     */
    private function handlerCases(): array
    {
        preg_match_all("/case\s+'([A-Za-z0-9]+)'\s*:/", $this->handlerSource(), $m);

        return array_values(array_unique($m[1]));
    }

    private function handlerSource(): string
    {
        $path = ROOTPATH . self::HANDLER;
        $this->assertFileExists($path, 'BuildingHandlerAction не найден — гейт был бы фиктивно зелёным.');

        return (string) file_get_contents($path);
    }
}
